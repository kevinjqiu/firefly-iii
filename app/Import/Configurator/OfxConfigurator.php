<?php
/**
 * OfxConfigurator.php
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

namespace FireflyIII\Import\Configurator;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\ImportJob;
use FireflyIII\Support\Import\Configuration\ConfigurationInterface;
use FireflyIII\Support\Import\Configuration\Ofx\Import;
use Log;

/**
 * Class OfxConfigurator
 *
 * @package FireflyIII\Import\Configurator
 */
class OfxConfigurator implements ConfiguratorInterface
{
    /** @var  ImportJob */
    private $job;

    /** @var string */
    private $warning = '';

    /**
     * ConfiguratorInterface constructor.
     */
    public function __construct()
    {
    }

    /**
     * Store any data from the $data array into the job.
     *
     * @param array $data
     *
     * @return bool
     * @throws FireflyException
     */
    public function configureJob(array $data): bool
    {
        $class = $this->getConfigurationClass();
        $job   = $this->job;
        /** @var ConfigurationInterface $object */
        $object = new $class($this->job);
        $object->setJob($job);
        $result        = $object->storeConfiguration($data);
        $this->warning = $object->getWarningMessage();

        return $result;
    }

    /**
     * Return the data required for the next step in the job configuration.
     *
     * @return array
     * @throws FireflyException
     */
    public function getNextData(): array
    {
        $class = $this->getConfigurationClass();
        $job   = $this->job;
        /** @var ConfigurationInterface $object */
        $object = app($class);
        $object->setJob($job);

        return $object->getData();

    }

    /**
     * @return string
     * @throws FireflyException
     */
    public function getNextView(): string
    {
        return 'import.ofx.import';
    }

    /**
     * Return possible warning to user.
     *
     * @return string
     */
    public function getWarningMessage(): string
    {
        return $this->warning;
    }

    /**
     * @return bool
     */
    public function isJobConfigured(): bool
    {
        return true;
    }

    /**
     * @param ImportJob $job
     */
    public function setJob(ImportJob $job)
    {
        $this->job = $job;
        if (is_null($this->job->configuration) || count($this->job->configuration) === 0) {
            Log::debug(sprintf('Gave import job %s initial configuration.', $this->job->key));
            //$this->job->configuration = config('csv.default_config');
            $this->job->save();
        }
    }

    /**
     * @return string
     * @throws FireflyException
     */
    private function getConfigurationClass(): string
    {
        return Import::class;
    }
}
