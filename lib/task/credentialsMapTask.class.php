<?php
/**
 *
 * @author omansour
 *
 */
class credentialsMapTask extends sfBaseTask
{
  protected function configure()
  {
    $this->namespace = 'dev-utils';
    $this->name = 'credentials-map';
    $this->briefDescription = 'list all actions and credneitals associated';

  }

  protected function execute($arguments = array(), $options = array())
  {

    set_time_limit(3600);

    $credentials_map = array();
    // detection des applications
    $apps = sfFinder::type('dir')->maxdepth(0)->relative()->sort_by_name()->in(sfConfig::get('sf_apps_dir'));
    foreach ($apps as $app)
    {
      // decouverte des modules interne de l'application
      $i_modules = sfFinder::type('dir')->maxdepth(0)->in(sfConfig::get('sf_root_dir').'/apps/'.$app.'/modules/');

      foreach ($i_modules as $module)
      {
        $actions = self::getActions($module);
        foreach ($actions as $action)
        {
          //          die(var_dump(self::getCredentials($app, basename($module), $action)));
          $credentials_map[$app][basename($module)][$action] = self::getCredentials($app, basename($module), $action);
        }
      }

      /**/
      // decouverte des modules externes (plugins) de l'application
      $settings = sfYaml::load(sfConfig::get('sf_root_dir').'/apps/'.$app.'/config/settings.yml');
      // TODO : gère uniquement les modules activés dans tous les environnement
      $modules = $settings['all']['.settings']['enabled_modules'];
      foreach (sfFinder::type('dir')->in(sfConfig::get('sf_plugins_dir')) as $dir)
      {
        foreach ($modules as $module_name)
        {
          if (file_exists($dir.'/modules/'.$module_name.'/'))
          {
            $actions = self::getActions($dir.'/modules/'.$module_name);
            foreach ($actions as $action)
            {
              if (array_key_exists($module_name, $credentials_map[$app]) and array_key_exists($action, $credentials_map[$app][$module_name]))
              {
                die(sprintf("Ce cas n'est pas géré - TODO (app : %s) (module : %s) (action: %s)", $app, $module_name, $action));
              }
              else
              {
                $credentials_map[$app][$module_name][$action] = self::getCredentials($app, $module_name, $action);
              }

            }
          }
        }
      }
      /**/


    }
    ksort($credentials_map);
    foreach ($credentials_map as $app => $modules)
    {
      echo "======================================================================".strtolower($app)."======================================================================\n";
      ksort($modules);
      foreach ($modules as $module => $actions)
      {
        ksort($actions);
        foreach ($actions as $action => $credentials)
        {
          echo sprintf('%10s %40s %40s : ', $app, $module, $action);

          if (is_array($credentials))
          {
            echo join(',', $credentials);
          }
          else
          {
            echo $credentials;
          }

          echo "\n";
        }
      }
    }
    echo "\n";
  }


  private static function getActions($module_path)
  {
    $to_return = array();
    if (file_exists($module_path.'/actions/actions.class.php'))
    {
      $methods = self::file_get_class_methods($module_path.'/actions/actions.class.php');

      foreach ($methods as $method)
      {
        if (strpos($method, 'execute') === 0)
        {
          $method = str_replace('execute', '', $method);
          $to_return[] =  (string)(strtolower(substr($method,0,1)).substr($method,1));
        }
      }
    }
    $other_action_files = sfFinder::type('file')->name('*Action.class.php')->in($module_path.'/actions/');
    foreach ($other_action_files as $action_file)
    {
      $to_return[] = str_replace('Action.class.php', '', basename($action_file));
    }
    return $to_return;
  }

  private static function getCredentials($app_name, $module_name, $action_name)
  {
    $configFiles = array();
    $configFiles[] = sfConfig::get('sf_root_dir')."/lib/vendor/symfony/lib/config/config/security.yml";
    $configFiles[] = sfConfig::get('sf_root_dir').'/apps/'.$app_name."/config/security.yml";
    $configFiles[] = sfConfig::get('sf_root_dir').'/apps/'.$app_name."/modules/".$module_name."/config/security.yml";

    //$plugin_security = sfFinder::type('file')->name('security.yml')->in(sfConfig::get('sf_plugins_dir').'/*/modules/'.$module_name.'/config/');
    foreach (sfFinder::type('dir')->in(sfConfig::get('sf_plugins_dir')) as $dir)
    {
      if (file_exists($dir.'/modules/'.$module_name.'/config/security.yml'))
      {
        $configFiles[] = $dir.'/modules/'.$module_name.'/config/security.yml';
      }
    }

    for($i = 0; $i < count($configFiles); $i++)
    {
      if (!is_readable($configFiles[$i]))
      {
        unset($configFiles[$i]);
      }
    }
    $configurations[$module_name] = sfSecurityConfigHandler::getConfiguration($configFiles);
    return self::getCredentialForModuleAction($configurations[$module_name], $action_name);
  }


  private static function getCredentialForModuleAction($module_security, $action_name)
  {
    $actionName = strtolower($action_name);

    if (isset($module_security[$actionName]['is_secure']) and ($module_security[$actionName]['is_secure'] === false))
    {
      return 'OFF';
    }

    if (isset($module_security[$actionName]['credentials']))
    {
      if (is_string($module_security[$actionName]['credentials']))
      {
        $module_security[$actionName]['credentials'] = array($module_security[$actionName]['credentials']);
      }
      $credentials = $module_security[$actionName]['credentials'];
    }
    else if (isset($module_security['all']['credentials']))
    {
      if (is_string($module_security[$actionName]['credentials']))
      {
        $module_security[$actionName]['credentials'] = array($module_security[$actionName]['credentials']);
      }
      $credentials = $module_security['all']['credentials'];
    }
    else
    {
      $credentials = null;
    }

    if (is_array($credentials))
    {
      $ret = array();
      foreach ($credentials as $c)
      {
        $str = '';
        if (is_array($c))
        {
          $str .= '(';
          $str .= join (' OR ', $c);
          $str .= ')';
        }
        else
        {
          $str = $c;
        }
        $ret[] = $str;
      }
      return join(' AND ',$ret);
    }
    else
    {
      return $credentials;
    }
  }

  /*
   * one classe per file
   */
  private static function file_get_class_methods ($file)
  {
    $arr = file($file);
    $arr_methods = array();
    foreach ($arr as $line)
    {
      if (ereg ('function ([_A-Za-z0-9]+)', $line, $regs))
      $arr_methods[] = $regs[1];
    }
    return $arr_methods;
  }
}