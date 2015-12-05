<?php

namespace Terminus\Commands;

use Terminus;
use Terminus\Auth;
use Terminus\Utils;
use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Helpers\Input;
use Terminus\Models\User;
use Terminus\Models\Collections\Sites;

define("WORKFLOWS_WATCH_INTERVAL", 5);

/**
* Actions to be taken on an individual site
*/
class WorkflowsCommand extends TerminusCommand {
  protected $_headers = false;

  /**
   * Object constructor.
   *
   * @return [WorkflowsCommand] $this
   */
  public function __construct() {
    Auth::ensureLogin();
    parent::__construct();
    $this->sites = new Sites();
  }

  /**
   * List Worflows for a Site
   *
   * ## OPTIONS
   * [--site=<site>]
   * : Site from which to list workflows
   *
   * @subcommand list
   */
  public function index($args, $assoc_args) {
    $site      = $this->sites->get(Input::sitename($assoc_args));
    $workflows = $site->workflows->all();
    $data      = array();
    foreach ($workflows as $workflow) {
      $workflow_data = $workflow->serialize();
      unset($workflow_data['operations']);
      $data[] = $workflow_data;
    }
    if (count($data) == 0) {
      $this->log()->warning(
        'No workflows have been run on {site}.',
        array('site' => $site->get('name'))
      );
    }
    $this->output()->outputRecordList($data, array('update' => 'Last update'));
  }

  /**
   * Show operation details for a workflow
   *
   * ## OPTIONS
   * [--workflow_id]
   * : Uuid of workflow to show
   * [--site=<site>]
   * : Site from which to list workflows
   *
   * @subcommand show
   */
  public function show($args, $assoc_args) {
    $site     = $this->sites->get(Input::sitename($assoc_args));
    $site->workflows->fetchWithOperations(array('paged' => false));
    $workflows = $site->workflows->all();
    $workflow = Input::workflow($workflows, $assoc_args, 'workflow_id');

    $workflow_data = $workflow->serialize();
    if (Terminus::getConfig('format') == 'normal') {
      $operations_data = $workflow_data['operations'];
      unset($workflow_data['operations']);

      $this->output()->outputRecord($workflow_data);

      if (count($operations_data)) {
        $this->log()->info('Workflow operations:');
        $this->output()->outputRecordList($operations_data);
      } else {
        $this->log()->info('Workflow has no operations');
      }
    } else {
      $this->output()->outputRecord($workflow_data);
    }
  }

  /**
   * Show quicksilver logs from a workflow
   *
   * ## OPTIONS
   * [--latest]
   * : Display the most-recent workflow with logs
   * [--workflow_id]
   * : Uuid of workflow to fetch logs for
   * [--site=<site>]
   * : Site from which to list workflows
   *
   * @subcommand logs
   */
  public function logs($args, $assoc_args) {
    $site = $this->sites->get(Input::sitename($assoc_args));
    if (isset($assoc_args['latest'])) {
      $site->workflows->fetchWithOperationsAndLogs(array('paged' => false));
      $workflow = $site->workflows->findLatestWithLogs();
      if (is_null($workflow)) {
        return $this->failure('No recent workflows contain logs');
      }
    } else {
      $site->workflows->fetchWithOperations(array('paged' => false));
      $workflows = $site->workflows->all();
      $workflow  = Input::workflow($workflows, $assoc_args, 'workflow_id');
      $workflow->fetchWithLogs();
    }

    if (Terminus::getConfig('format') == 'normal') {
      $operations = $workflow->operations();
      if (count($operations) == 0) {
        $this->log()->info('Workflow has no operations');
        return;
      }

      $operations_with_logs = array_filter(
        $operations,
        function($operation) {
          return $operation->get('log_output');
        }
      );

      if (count($operations_with_logs) == 0) {
        $this->log()->info('Workflow has no operations with logs');
        return;
      }

      foreach ($operations as $operation) {
        if ($operation->get('log_output')) {
          $operation_data = $operation->serialize();
          $this->output()->outputRecord($operation_data);
        }
      }
    } else {
      $workflow_data = $workflow->serialize();
      $operations_data = $workflow_data['operations'];
      $this->output()->outputRecordList($operations_data);
    }
  }

  /**
   * Streams new and finished workflows to the console
   *
   * ## OPTIONS
   * [--site=<site>]
   * : Site from which to list workflows
   *
   * @subcommand watch
   */
  public function watch($args, $assoc_args) {
    $site = $this->sites->get(Input::sitename($assoc_args));

    // Keep track of workflows that have been printed.
    // This is necessary because the local clock may drift from
    // the server's clock, causing events to be printed twice.
    $started = array();
    $finished = array();

    $this->logger->info('Watching workflows...');
    while (true) {
      $last_checked = time();
      sleep(WORKFLOWS_WATCH_INTERVAL);

      $site->workflows->fetchWithOperations();
      $workflows = $site->workflows->all();
      foreach ($workflows as $workflow) {
        if (($workflow->get('created_at') > $last_checked)
          && !in_array($workflow->id, $started)
        ) {
          $started_message = sprintf(
            "%s Started %s (%s)",
            $workflow->id,
            $workflow->get('description'),
            $workflow->get('environment')
          );
          $this->logger->info($started_message);
          array_push($started, $workflow->id);
        }

        if (($workflow->get('finished_at') > $last_checked)
          && !in_array($workflow->id, $finished)
        ) {
          $finished_message = sprintf(
            "%s Finished %s (%s)",
            $workflow->id,
            $workflow->get('description'),
            $workflow->get('environment')
          );
          $this->logger->info($finished_message);
          array_push($finished, $workflow->id);
        }
      }
    }
  }

}

Terminus::addCommand('workflows', 'WorkflowsCommand');