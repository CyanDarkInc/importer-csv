<?php
/**
 * Generic Csv Migrator.
 *
 * @package blesta
 * @subpackage blesta.plugins.import_manager.components.migrators.csv
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class CsvMigrator extends Migrator
{
    /**
     * @var array An array of settings
     */
    protected $settings;

    /**
     * @var bool Enable/disable debugging
     */
    protected $enable_debug = false;

    /**
     * @var string Default conutry code
     */
    protected $default_country = 'US';

    /**
     * Runs the import, sets any Input errors encountered.
     */
    public function import()
    {
        $actions = [
            'importClients' // works
        ];

        $errors = [];
        $this->startTimer('total time');
        foreach ($actions as $action) {
            try {
                $this->debug($action);
                $this->debug('-----------------');
                $this->startTimer($action);
                $this->{$action}();
                $this->endTimer($action);
                $this->debug("-----------------\n");
            } catch (Exception $e) {
                $errors[] = $action . ': ' . $e->getMessage() . ' on line ' . $e->getLine();
            }
        }

        if (!empty($errors)) {
            array_unshift($errors, Language::_('Csv1_0.!error.import', true));
            $this->Input->setErrors(['error' => $errors]);
        }
        $this->endTimer('total time');

        if ($this->enable_debug) {
            $this->debug(print_r($this->Input->errors(), true));
            exit;
        }
    }

    /**
     * Import clients.
     */
    protected function importClients()
    {
        // Load required models
        Loader::loadModels($this, ['Users', 'ClientGroups']);

        // Get users
        $users = $this->remote;

        // Get default client group
        $client_group = $this->ClientGroups->getDefault(Configure::get('Blesta.company_id'));

        // Import clients
        $this->local->begin();
        foreach ($users as $user) {
            // Create user account
            $vars = [
                'username' => isset($user[$this->mappings['remote_fields']['username']]) ? $user[$this->mappings['remote_fields']['username']] : $user[$this->mappings['remote_fields']['email']],
                'password' => password_hash($this->generatePassword(), PASSWORD_BCRYPT),
                'date_added' => $this->Users->dateToUtc(date('c'))
            ];
            $this->local->insert('users', $vars);
            $user_id = $this->local->lastInsertId();

            // Get latest client
            $latest_client = $this->local->select()->from('clients')->order(['id' => 'desc'])->fetch();

            // Create client account
            $vars = [
                'id_format' => '{num}',
                'id_value' => $latest_client->id_value + 1,
                'user_id' => $user_id,
                'client_group_id' => $client_group->id,
                'status' => 'active'
            ];
            $this->local->insert('clients', $vars);
            $client_id = $this->local->lastInsertId();


            // Create primary contact
            $vars = [
                'client_id' => $client_id,
                'contact_type' => 'primary',
                'first_name' => $user[$this->mappings['remote_fields']['first_name']],
                'last_name' => $user[$this->mappings['remote_fields']['last_name']],
                'company' => isset($user[$this->mappings['remote_fields']['company']]) ? $user[$this->mappings['remote_fields']['company']] : null,
                'email' => isset($user[$this->mappings['remote_fields']['email']]) ? $user[$this->mappings['remote_fields']['email']] : null,
                'address1' => isset($user[$this->mappings['remote_fields']['address1']]) ? $user[$this->mappings['remote_fields']['address1']] : null,
                'address2' => isset($user[$this->mappings['remote_fields']['address2']]) ? $user[$this->mappings['remote_fields']['address2']] : null,
                'city' => isset($user[$this->mappings['remote_fields']['city']]) ? $user[$this->mappings['remote_fields']['city']] : null,
                'zip' => isset($user[$this->mappings['remote_fields']['zip']]) ? $user[$this->mappings['remote_fields']['zip']] : null,
                'state' => isset($user[$this->mappings['remote_fields']['state']]) ? strtoupper(substr($user[$this->mappings['remote_fields']['state']], 0, 2)) : null,
                'country' => isset($user[$this->mappings['remote_fields']['country']]) ? strtoupper(substr($user[$this->mappings['remote_fields']['country']], 0, 2)) : $this->default_country,
                'date_added' => $this->Users->dateToUtc($client->datecreated)
            ];
            $this->local->insert('contacts', $vars);
            $contact_id = $this->local->lastInsertId();

            // Add contact phone number
            if (isset($user[$this->mappings['remote_fields']['phone']]) && $user[$this->mappings['remote_fields']['phone']] != '') {
                $vars = [
                    'contact_id' => $contact_id,
                    'number' => $user[$this->mappings['remote_fields']['phone']],
                    'type' => 'phone',
                    'location' => 'home'
                ];
                $this->local->insert('contact_numbers', $vars);
            }
        }
        $this->local->commit();
    }

    /**
     * Generates a password.
     *
     * @param int $min_length The minimum character length for the password (5 or larger)
     * @param int $max_length The maximum character length for the password (14 or fewer)
     * @return string The generated password
     */
    private function generatePassword($min_length = 5, $max_length = 10)
    {
        $pool = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $pool_size = strlen($pool);
        $length = mt_rand(max($min_length, 5), min($max_length, 14));
        $password = '';

        for ($i=0; $i < $length; $i++) {
            $password .= substr($pool, mt_rand(0, $pool_size - 1), 1);
        }

        return $password;
    }

    /**
     * Load the given local model.
     *
     * @param string $name The name of the model to load
     */
    protected function loadModel($name)
    {
        $name = Loader::toCamelCase($name);
        $file = Loader::fromCamelCase($name);
        Loader::load($this->path . DS . 'models' . DS . $file . '.php');
        $this->{$name} = new $name($this->remote);
    }

    /**
     * Set debug data.
     *
     * @param string $str The debug data
     */
    protected function debug($str)
    {
        static $set_buffering = false;

        if ($this->enable_debug) {
            if (!$set_buffering) {
                ini_set('output_buffering', 'off');
                ini_set('zlib.output_compression', false);
                @ob_end_flush();

                ini_set('implicit_flush', true);
                ob_implicit_flush(true);

                header('Content-type: text/plain');
                header('Cache-Control: no-cache');
                $set_buffering = true;
            }

            echo $str . "\n";

            @ob_flush();
            flush();
        }
    }

    /**
     * Start a timer for the given task.
     *
     * @param string $task
     */
    protected function startTimer($task)
    {
        $this->task[$task] = ['start' => microtime(true), 'end' => 0, 'total' => 0];
    }

    /**
     * Pause a timer for the given task.
     *
     * @param string $task
     */
    protected function pauseTimer($task)
    {
        $this->task[$task]['end'] = microtime(true);
        $this->task[$task]['total'] += ($this->task[$task]['end'] - $this->task[$task]['start']);
    }

    /**
     * Unpause a timer for the given task.
     *
     * @param string $task
     */
    protected function unpauseTimer($task)
    {
        $this->task[$task]['start'] = microtime(true);
    }

    /**
     * End a timer for the given task, output to debug.
     *
     * @param string $task
     */
    protected function endTimer($task)
    {
        if ($this->task[$task]['start'] > $this->task[$task]['end']) {
            $this->pauseTimer($task);
        }

        if ($this->enable_debug) {
            $this->debug($task . ' took: ' . round($this->task[$task]['total'], 4) . ' seconds');
        }
    }
}
