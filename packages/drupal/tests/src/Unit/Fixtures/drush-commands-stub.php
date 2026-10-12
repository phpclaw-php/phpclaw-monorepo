<?php

declare(strict_types=1);

namespace Drush\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

if (! class_exists(DrushCommands::class)) {
    abstract class DrushCommands
    {
        public const REQ = InputOption::VALUE_REQUIRED;

        public const OPT = InputOption::VALUE_OPTIONAL;

        public const EXIT_SUCCESS = 0;

        public const EXIT_FAILURE = 1;

        protected ?InputInterface $input = null;

        protected ?OutputInterface $output = null;

        protected ?LoggerInterface $logger = null;

        public function __construct() {}

        public function setInput(InputInterface $input): void
        {
            $this->input = $input;
        }

        public function setOutput(OutputInterface $output): void
        {
            $this->output = $output;
        }

        public function setLogger(LoggerInterface $logger): void
        {
            $this->logger = $logger;
        }

        protected function input(): InputInterface
        {
            return $this->input ??= new ArgvInput;
        }

        protected function output(): OutputInterface
        {
            return $this->output ??= new NullOutput;
        }

        public function logger(): ?LoggerInterface
        {
            return $this->logger;
        }
    }
}
