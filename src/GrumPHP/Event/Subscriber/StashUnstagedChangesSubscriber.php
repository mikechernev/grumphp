<?php

namespace GrumPHP\Event\Subscriber;

use Exception;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use GrumPHP\Configuration\GrumPHP;
use GrumPHP\Event\RunnerEvent;
use GrumPHP\Event\RunnerEvents;
use GrumPHP\Exception\RuntimeException;
use GrumPHP\IO\IOInterface;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class StashUnstagedChangesSubscriber
 *
 * @package GrumPHP\Event\Subscriber
 */
class StashUnstagedChangesSubscriber implements EventSubscriberInterface
{

    /**
     * @var GrumPHP
     */
    private $grumPHP;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var bool
     */
    private $stashIsApplied = false;

    /**
     * @var bool
     */
    private $shutdownFunctionRegistered = false;

    /**
     * @param GrumPHP     $grumPHP
     * @param Repository  $repository
     * @param IOInterface $io
     */
    public function __construct(GrumPHP $grumPHP, Repository $repository, IOInterface $io)
    {
        $this->grumPHP = $grumPHP;
        $this->repository = $repository;
        $this->io = $io;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            RunnerEvents::RUNNER_RUN => array('saveStash', 10000),
            RunnerEvents::RUNNER_COMPLETE => array('popStash', -10000),
            RunnerEvents::RUNNER_FAILED => array('popStash', -10000),
            ConsoleEvents::EXCEPTION => array('handleErrors', -10000),
        );
    }

    /**
     * @param RunnerEvent $e
     *
     * @return void
     */
    public function saveStash(RunnerEvent $e)
    {
        if (!$this->isStashEnabled($e->getContext())) {
            return;
        }

        $this->doSaveStash();
    }

    /**
     * @param RunnerEvent $e
     *
     * @return void
     * @throws ProcessException
     */
    public function popStash(RunnerEvent $e)
    {
        if (!$this->isStashEnabled($e->getContext())) {
            return;
        }

        $this->doPopStash();
    }

    /**
     * @return void
     */
    public function handleErrors()
    {
        if (!$this->grumPHP->ignoreUnstagedChanges()) {
            return;
        }

        $this->doPopStash();
    }

    /**
     * Check if there is a pending diff and stash the changes.
     *
     * @reurn void
     */
    private function doSaveStash()
    {
        $pending = $this->repository->getWorkingCopy()->getDiffPending();
        if (!count($pending->getFiles())) {
             return;
        }

        try {
            $this->io->write('<fg=yellow>Detected unstaged changes... Stashing them!</fg=yellow>');
            $this->repository->run('stash', array('save', '--quiet', '--keep-index', uniqid('grumphp')));
        } catch (Exception $e) {
            // No worries ...
            $this->io->write(sprintf('<fg=red>Failed stashing changes: %s</fg=red>', $e->getMessage()));
            return;
        }

        $this->stashIsApplied = true;
        $this->registerShutdownHandler();
    }

    /**
     * @return void
     */
    private function doPopStash()
    {
        if (!$this->stashIsApplied) {
            return;
        }

        try {
            $this->io->write('<fg=yellow>Reapplying unstaged changes from stash.</fg=yellow>');
            $this->repository->run('stash', array('pop', '--quiet'));
        } catch (Exception $e) {
            throw new RuntimeException(
                'The stashed changes could not be applied. Please run `git stash pop` manually!'
                . 'More info: ' . $e->__toString(),
                0,
                $e
            );
        }

        $this->stashIsApplied = false;
    }

    /**
     * @param ContextInterface $context
     *
     * @return bool
     */
    private function isStashEnabled(ContextInterface $context)
    {
        return $this->grumPHP->ignoreUnstagedChanges() && $context instanceof GitPreCommitContext;
    }

    /**
     * Make sure to fetch errors and pop the stash before crashing
     *
     * @return void
     */
    private function registerShutdownHandler()
    {
        if ($this->shutdownFunctionRegistered) {
            return;
        }

        $subscriber = $this;
        register_shutdown_function(function () use ($subscriber) {
            if (!error_get_last()) {
                return;
            }

            $subscriber->handleErrors();
        });

        $this->shutdownFunctionRegistered = true;
    }
}
