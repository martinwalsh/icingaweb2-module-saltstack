<?php

namespace Icinga\Module\Saltstack\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Exception\JsonException;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use RuntimeException;

ini_set("log_errors", 1);
ini_set("error_log", "/tmp/php-error.log");


class ImportSource extends ImportSourceHook
{
    protected $db;

    public function getName()
    {
        return 'Import from SaltStack (saltstack)';
    }

    /**
     * @return object[]
     * @throws ConfigurationError
     * @throws IcingaException
     */
    public function fetchData()
    {
      // $result = array();

      // // if ($this->getSetting('query_type') == 'host') {
      // $result = array_merge($result, $this->getHosts());
      // // }

      // return $result;
      return $this->getHosts();
    }

    /**
     * @return array
     * @throws ConfigurationError
     * @throws IcingaException
     */
    public function listColumns()
    {
      return array(
        'hostname',
        'ip_address',
        'host_template'
      );
    }

    /**
     * @param QuickForm $form
     * @return \Icinga\Module\Director\Forms\ImportSourceForm|QuickForm
     * @throws \Zend_Form_Exception
     */
    public static function addSettingsFormFields(QuickForm $form)
    {
      $form->addElement('text', 'master_host', array(
        'label' => 'Salt Master Host',
        'required' => true
      ));

      $form->addElement('text', 'api_username', array(
        'label' => 'Salt API Username',
        'required' => true
      ));

      $form->addElement('text', 'api_password', array(
        'label' => 'Salt API Password',
        'required' => true
      ));

      $form->addElement('text', 'default_host_template', array(
        'label' => 'Default Host Template',
        'description' => 'The name of the Host Template to use for this minion',
        'required' => false
      ));
    }

    protected function getMasterHost() {
      return (string) $this->getSetting('master_host');

    }

    protected function getUrl($url = '/')
    {
      return 'https://' . $this->getMasterHost() . ':8080' . $url;
    }

    protected function querySalt($action, $target = '*', $args = null, $kwds = null)
    {
      $auth = array(
        'username' => $this->getSetting('api_username'),
        'password' => $this->getSetting('api_password'),
        'eauth' => $this->getSetting('eauth', 'pam')
      );

      $token = $this->request('post', '/login', null, $auth)['return'][0]['token'];

      error_log("Token: " . $token);

      $params = array(
        'client' => 'local',
        'tgt' => $target,
        'fun' => $action,
        'arg' => (array) $args,
        'kwarg' => (array) $kwds
      );

      return $this->request('post', '/', array('X-Auth-Token: ' . $token), $params);

    }

    protected function getHosts()
    {
      $res = $this->querySalt('test.ping');

      error_log("Response : " . var_export($res, true));

      $hosts = array();
      foreach ($res['return'][0] as $host => $connected) {
        error_log('Found host : ' . $host . '(' . var_export($connected, true) . ')');

        if (!$connected) {
          continue;
        }

        $grains = $this->querySalt(
          'grains.item', $host, array('host_ip4', 'icinga:host_template')
        )['return'][0][$host];

        error_log("Host: " . $host . " Grains: " . var_export($grains, true));

        $host_template = $grains['icinga:host_template'];
        if ( empty($grains['icinga:host_template']) ) {
          $host_template = $this->getSetting('default_host_template', 'External Hosts');
        }

        $row = array(
          'hostname' => $host,
          'ip_address' => $grains['host_ip4'],
          'host_template' => $host_template
        );

        error_log("Row: " . var_export($row, true));

        array_push($hosts, (object) $row);
      }

      error_log('Hosts: ' . var_export($hosts, true));

      return (array) $hosts;
    }

    protected function request($method, $url = '/', $headers = null, $body = null)
    {
        $headers = array_merge(array(
            'Host: ' . $this->getMasterHost() . ':8080',
            'Connection: close',
            'Content-Type: application/json'
        ), (array) $headers);

        if ($body !== null) {
            $body = json_encode($body);
        }

        $opts = array(
            'http' => array(
                'protocol_version' => '1.1',
                'user_agent'       => 'Icinga Web 2.0 - Director',
                'method'           => strtoupper($method),
                'content'          => $body,
                'header'           => $headers,
                'ignore_errors'    => true
            ),
            'ssl' => array(
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'verify_expiry'    => true
            )
        );

        $context = stream_context_create($opts);
        $res = file_get_contents($this->getUrl($url), false, $context);

        $response_header = array_shift($http_response_header);

        if (substr($response_header, 0, 10) !== 'HTTP/1.1 2') {
            throw new RuntimeException(
              sprintf(
                'Headers: %s, Response: %s, Code: %s, Request Headers: %s',
                implode("\n", $http_response_header),
                var_export($res, 1),
                substr($response_header, 9, 12),
                implode(', ', $headers)
              )
            );
        }

        return json_decode($res, true);
    }
}
