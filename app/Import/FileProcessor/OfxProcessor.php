<?php
/**
 * OfxProcessor.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Import\FileProcessor;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Import\Object\ImportJournal;
use FireflyIII\Models\ImportJob;
use FireflyIII\Models\TransactionJournalMeta;
use Illuminate\Support\Collection;
use Iterator;
use Log;

/**
 * Class OfxProcessor, as the name suggests, goes over OFX file and creates
 * "ImportJournal" objects, which are used in another step to create new journals and transactions
 * and what-not.
 *
 * @package FireflyIII\Import\FileProcessor
 */
class OfxProcessor implements FileProcessorInterface
{
    /** @var  ImportJob */
    private $job;
    /** @var Collection */
    private $objects;
    /** @var array */
    private $validConverters = [];

    /**
     * FileProcessorInterface constructor.
     */
    public function __construct()
    {
        $this->objects         = new Collection;
    }

    /**
     * @return Collection
     */
    public function getObjects(): Collection
    {
        return $this->objects;
    }

    /**
     * Does the actual job.
     *
     * @return bool
     */
    public function run(): bool
    {
        Log::debug('Now in OfxProcessor run(). Job is now running...');

        $entries = new Collection($this->getImportArray());
        Log::notice('Building importable objects from OFX file.');
        Log::debug(sprintf('Number of entries: %d', $entries->count()));
        $notImported = $entries->filter(
            function (array $row, int $index) {
                if ($this->rowAlreadyImported($row)) {
                    $message = sprintf('Row #%d has already been imported.', $index);
                    $this->job->addError($index, $message);
                    $this->job->addStepsDone(5); // all steps.
                    Log::info($message);

                    return null;
                }

                return $row;
            }
        );

        Log::debug(sprintf('Number of entries left: %d', $notImported->count()));

        $notImported = $entries;
        $notImported->each(
            function (array $row, int $index) {
                $journal = $this->importRow($index, $row);
                $this->objects->push($journal);
                $this->job->addStepsDone(1);
            }
        );

        return true;
    }

    /**
     * Set import job for this processor.
     *
     * @param ImportJob $job
     *
     * @return FileProcessorInterface
     */
    public function setJob(ImportJob $job): FileProcessorInterface
    {
        $this->job = $job;

        return $this;
    }

    /**
     * @return Iterator
     */
    private function getImportArray(): Iterator
    {
        $content   = $this->job->uploadFileContents();
        $parser = new \OfxParser\Parser();
        $ofx = $parser->loadFromString($content);
        if (count($ofx->bankAccounts) == 0) {
            return new \ArrayIterator(array());
        }
        if (count($ofx->bankAccounts) > 1) {
            throw new FireflyException("Only one account per OFX file is supported.");
        }

        $attrs = array(
            'type'        => function($v) { return $v; },
            'amount'      => function($v) { return strval($v); },
            'date'        => function(\DateTimeInterface $v) { return date("Y/m/d", $v->getTimestamp()); },
            'name'        => function($v) { return $v; },
            'memo'        => function($v) { return $v; }
        );
        $transactions = array_map(function($ofxTxn) use ($attrs) {
            $retval = array();
            foreach ($attrs as $attr => $converter) {
                $retval[$attr] = $converter($ofxTxn->$attr);
            }
            return $retval;
        }, $ofx->bankAccounts[0]->statement->transactions);
        return new \ArrayIterator($transactions);
    }

    /**
     * Will return string representation of JSON error code.
     *
     * @param int $jsonError
     *
     * @return string
     */
    private function getJsonError(int $jsonError): string
    {
        $messages = [
            JSON_ERROR_NONE                  => 'No JSON error',
            JSON_ERROR_DEPTH                 => 'The maximum stack depth has been exceeded.',
            JSON_ERROR_STATE_MISMATCH        => 'Invalid or malformed JSON.',
            JSON_ERROR_CTRL_CHAR             => 'Control character error, possibly incorrectly encoded.',
            JSON_ERROR_SYNTAX                => 'Syntax error.',
            JSON_ERROR_UTF8                  => 'Malformed UTF-8 characters, possibly incorrectly encoded.',
            JSON_ERROR_RECURSION             => 'One or more recursive references in the value to be encoded.',
            JSON_ERROR_INF_OR_NAN            => 'One or more NAN or INF values in the value to be encoded.',
            JSON_ERROR_UNSUPPORTED_TYPE      => 'A value of a type that cannot be encoded was given.',
            JSON_ERROR_INVALID_PROPERTY_NAME => 'A property name that cannot be encoded was given.',
            JSON_ERROR_UTF16                 => 'Malformed UTF-16 characters, possibly incorrectly encoded.',
        ];
        if (isset($messages[$jsonError])) {
            return $messages[$jsonError];
        }

        return 'Unknown JSON error';
    }

    /**
     * Hash an array and return the result.
     *
     * @param array $array
     *
     * @return string
     * @throws FireflyException
     */
    private function getRowHash(array $array): string
    {
        $json      = json_encode($array);
        $jsonError = json_last_error();

        if ($json === false) {
            throw new FireflyException(sprintf('Error while encoding JSON for OFX row: %s', $this->getJsonError($jsonError)));
        }
        $hash = hash('sha256', $json);

        return $hash;
    }

    /**
     * Take a row, build import journal by annotating each value and storing it in the import journal.
     *
     * @param int   $index
     * @param array $row
     *
     * @return ImportJournal
     * @throws FireflyException
     */
    private function importRow(int $index, array $row): ImportJournal
    {
        Log::debug(sprintf('Now at row %d', $index));
        $hash = $this->getRowHash($row);
        $journal = new ImportJournal;
        $journal->setUser($this->job->user);
        $journal->setHash($hash);
        /**
         * @var int    $rowIndex
         * @var string $value
         */
        foreach ($row as $key => $value) {
            $value = trim($value);
            if (strlen($value) == 0) {
                continue;
            }
            $annotated = $this->annotateValue($key, $value);
            Log::debug('Annotated value', $annotated);
            if ($annotated != array()) {
                $journal->setValue($annotated);
            }
        }
        // TODO(kevinjqiu): add this to configuration
        $accountId = $this->job->configuration['import-account'];
        //$accountId = 1;
        $journal->asset->setDefaultAccountId($accountId);

        return $journal;
    }

    /**
     * Add meta data to the individual value and verify that it can be handled in a later stage.
     *
     * @param int    $index
     * @param string $value
     *
     * @return array
     * @throws FireflyException
     */
    private function annotateValue(string $key, string $value)
    {
        $roleMap = array(
            'type',
            'date',
            'amount',
            'uniqueId',
            'name',
            'memo',
            'sic',
            'checkNumber'
        );

        $role = null;
        switch ($key) {
        case 'amount':
            $role = 'amount';
            break;
        case 'date':
            $role = 'date-transaction';
            break;
        case 'name':
            $role = 'description';
            break;
        }

        if ($role === null) {
            return array();
        }

        $entry = [
            'role'   => $role,
            'value'  => $value,
        ];

        return $entry;
    }

    /**
     * Checks if the row has not been imported before.
     *
     * @param array $array
     *
     * @return bool
     */
    private function rowAlreadyImported(array $array): bool
    {
        $hash  = $this->getRowHash($array);
        $json  = json_encode($hash);
        $entry = TransactionJournalMeta::leftJoin('transaction_journals', 'transaction_journals.id', '=', 'journal_meta.transaction_journal_id')
                                       ->where('data', $json)
                                       ->where('name', 'importHash')
                                       ->first();
        if (!is_null($entry)) {
            return true;
        }

        return false;

    }
}
