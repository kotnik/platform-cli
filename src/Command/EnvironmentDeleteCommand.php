<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:delete')
          ->setDescription('Delete an environment')
          ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to delete')
          ->addOption('inactive', null, InputOption::VALUE_NONE, 'Delete all inactive environments')
          ->addOption('merged', null, InputOption::VALUE_NONE, 'Delete all merged environments');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $output);

        $environments = $this->getEnvironments();

        if ($input->getOption('inactive')) {
            $toDelete = array_filter(
              $environments,
              function ($environment) {
                  /** @var Environment $environment */
                  return $environment->status == 'inactive';
              }
            );
            if (!$toDelete) {
                $output->writeln("No inactive environments found");

                return 0;
            }
        } elseif ($input->getOption('merged')) {
            if (!$this->hasSelectedEnvironment()) {
                $output->writeln("No base environment specified");

                return 1;
            }
            $base = $this->getSelectedEnvironment()->id;
            $output->writeln("Finding environments merged with <info>$base</info>");
            $toDelete = $this->getMergedEnvironments($base);
            if (!$toDelete) {
                $output->writeln("No merged environments found");

                return 0;
            }
        } elseif ($this->hasSelectedEnvironment()) {
            $toDelete = array($this->getSelectedEnvironment());
        } else {
            $environmentIds = $input->getArgument('environment');
            $toDelete = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $output->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->deleteMultiple($toDelete, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param string $base
     *
     * @return array
     */
    protected function getMergedEnvironments($base)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }
        $environments = $this->getEnvironments($this->getSelectedProject(), true);
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        $gitHelper->execute(array('fetch', 'origin'));
        $mergedBranches = $gitHelper->getMergedBranches($base);
        $mergedEnvironments = array_intersect_key($environments, array_flip($mergedBranches));
        unset($mergedEnvironments[$base], $mergedEnvironments['master']);
        $parent = $environments[$base]['parent'];
        if ($parent) {
            unset($mergedEnvironments[$parent]);
        }

        return $mergedEnvironments;
    }

    /**
     * @param Environment[]   $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function deleteMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        // Confirm which environments the user wishes to be deleted.
        $delete = array();
        $deactivate = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment['id'];
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deactivated or deleted.");
                continue;
            }
            // Check that the environment does not have children.
            // @todo remove this check when Platform's behavior is fixed
            foreach ($this->getEnvironments() as $potentialChild) {
                if ($potentialChild['parent'] == $environment['id']) {
                    $output->writeln(
                      "The environment <error>$environmentId</error> has children and therefore can't be deleted."
                    );
                    $output->writeln("Please delete the environment's children first.");
                    continue 2;
                }
            }
            if ($environment->isActive()) {
                $output->writeln("The environment <comment>$environmentId</comment> is currently active: deleting it will delete all associated data.");
                $question = "Are you sure you want to delete the environment <comment>$environmentId</comment>?";
                if ($questionHelper->confirm($question, $input, $output)) {
                    $deactivate[$environmentId] = $environment;
                    $question = "Delete the remote Git branch too?";
                    if ($questionHelper->confirm($question, $input, $output)) {
                        $delete[$environmentId] = $environment;
                    }
                }
            }
            else {
                $question = "Are you sure you want to delete the remote Git branch <comment>$environmentId</comment>?";
                if ($questionHelper->confirm($question, $input, $output)) {
                    $delete[$environmentId] = $environment;
                }
            }
        }

        $deactivateActivities = array();
        $deactivated = 0;
        /** @var Environment $environment */
        foreach ($deactivate as $environmentId => $environment) {
            try {
                $output->writeln("Deleting environment <info>$environmentId</info>");
                $deactivateActivities[] = $environment->deactivate();
                $deactivated++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        ActivityUtil::waitMultiple($deactivateActivities, $output);

        $deleted = 0;
        foreach ($delete as $environmentId => $environment) {
            try {
                if ($environment->isActive()) {
                    $environment->refresh();
                }
                $environment->delete();
                $output->writeln("Deleted remote Git branch <info>$environmentId</info>");
                $deleted++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        if ($deleted || $deactivated) {
            $this->getEnvironments(null, true);
        }

        return $deleted >= count($delete) && $deactivated >= count($deactivate);
    }

}
