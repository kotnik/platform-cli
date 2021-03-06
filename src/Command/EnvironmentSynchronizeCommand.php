<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:synchronize')
          ->setAliases(array('sync'))
          ->setDescription('Synchronize an environment')
          ->addArgument(
            'synchronize',
            InputArgument::IS_ARRAY,
            'What to synchronize: code, data or both',
            null
          )
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the operation to complete');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $output);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];

        if (!$selectedEnvironment->operationAvailable('synchronize')) {
            $output->writeln(
              "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );

            return 1;
        }

        $parentId = $selectedEnvironment['parent'];

        $questionHelper = $this->getHelper('question');

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), array('code', 'data', 'both'))) {
                $output->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);
            if (!$questionHelper->confirm(
              "Are you sure you want to synchronize <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $output,
              false
            )
            ) {
                return 0;
            }
        } else {
            $syncCode = $questionHelper->confirm(
              "Synchronize code from <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $output,
              false
            );
            $syncData = $questionHelper->confirm(
              "Synchronize data from <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $output,
              false
            );
        }
        if (!$syncCode && !$syncData) {
            $output->writeln("<error>You must synchronize at least code or data.</error>");

            return 1;
        }

        $output->writeln("Synchronizing environment <info>$environmentId</info>");

        $activity = $selectedEnvironment->synchronize($syncData, $syncCode);
        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $output,
              "Synchronization complete",
              "Synchronization failed"
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
