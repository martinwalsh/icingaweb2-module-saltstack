<?php

namespace Icinga\Module\SaltStack\ProvidedHook\Director;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Exception\JsonException;
use Icinga\Module\Director\Web\Form\QuickForm;
use RuntimeException;


class ImportSource extends ImportSourceHook
{
    protected $db;


    public function getName()
    {
        return 'Import from SaltStack (saltstack)';
    }

    public function fetchData()
    {
      $result = array();

      // if ($this-getSetting('query_type') == 'host') {
      $result = array_merge($result, $this->fetchHostData());
      // }

      return $result;
    }

    public function listColumns()
    {
      return array(
        'hostname',
        'ip_address',
        'host_templates'
      );
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
      $form->addElement('select', 'master_host', array(
        'label' => 'Salt Master Host',
        'required' => true
      ));

      $form->addElement('select', 'api_username', array(
        'label' => 'Salt API Username',
        'required' => true
      ));

      $form->addElement('select', 'api_password', array(
        'label' => 'Salt API Password',
        'required' => true
      ));

      $form->addElement('select', 'default_host_templates', array(
        'label' => 'Default Host Templates',
        'description' => 'The comma delimited list of default host templates, if none are defined by salt',
        'required' => false
      ));
    }

    protected function getUrl($url = '/')
    {
      return 'https://' . $this->getSetting('master_host') . ':8080' . $url;
    }

    protected function getApi($action = 'test.ping', $target = '*', $args = array(), $kwds = array())
    {
      $auth = array(
        'username' => $this.getSettings('api_username'),
        'password' => $this.getSettings('api_password'),
        'eauth' => $this.getSettings('eauth', 'pam')
      );

      $token = json_decode(
        $this.request('post', '/login', $auth)
      )['return'][0]['token'];

      $params = array(
        'client' => 'local',
        'tgt' => $target,
        'fun' => $action,
        'arg' => $args,
        'kwarg' => $kwds
      );

      return json_decode($this.request('post', '/', $params));

    }

    protected function getHosts()
    {
      $res = $this.getApi();

      $hosts = array();
      foreach ($hosts as $host => $connected) {
        $grains = $this.getApi(
          'grains.item', $host, array('host_ip4', 'icinga:host_templates')
        )['return'][0][$host];

        array_push($hosts, array(
          'hostname' => $host,
          'ip_address' => $grains['host_ip4'],
          'host_templates' => $grains['icinga:host_templates'] || explode(',', $this.getSettings('default_host_templates', array('External Hosts')))
        ));
      }

      return $hosts;
    }

    protected function request($method, $url = '/', $headers = array(), $body = null)
    {
        $headers = merge_array($headers, array(
            'Host: ' . $host . ':8080',
            'Connection: close',
            'Content-Type: application/json'
        ));

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

        if (substr(array_shift($http_response_header), 0, 10) !== 'HTTP/1.1 2') {
            throw new RuntimeException(
                'Headers: %s, Response: %s',
                implode("\n", $http_response_header),
                var_export($res, 1)
            );
        }

        return $res;
    }
}
