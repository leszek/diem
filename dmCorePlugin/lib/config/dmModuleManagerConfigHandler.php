<?php

/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * @package    symfony
 * @subpackage config
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfRoutingConfigHandler.class.php 21923 2009-09-11 14:47:38Z fabien $
 */
class dmModuleManagerConfigHandler extends sfYamlConfigHandler
{
  protected
  $config,
  $modules,
  $projectModules;
  
  /**
   * Executes this configuration handler.
   *
   * @param array $configFiles An array of absolute filesystem path to a configuration file
   *
   * @return string Data to be written to a cache file
   *
   * @throws sfConfigurationException If a requested configuration file does not exist or is not readable
   * @throws sfParseException         If a requested configuration file is improperly formatted
   */
  public function execute($configFiles)
  {
    $config = sfFactoryConfigHandler::getConfiguration(sfContext::getInstance()->getConfiguration()->getConfigPaths('config/factories.yml'));
    
    $options = $config['module_manager']['param'];
    $managerClass = $config['module_manager']['class'];
    
    $this->parse($configFiles);
    
    $this->validate();
    
    $this->processHierarchy();
    
    $data = array();

    $data[] = sprintf('$options = %s;', var_export($options, true));

    $data[] = sprintf('$manager = new %s($options);', $managerClass);

    $data[] = sprintf('$modules = array(); $projectModules = array(); $modelModules = array();');

    $data[] = sprintf('$types = array();');

    foreach($this->config as $typeName => $typeConfig)
    {
      $data[] = sprintf('$types[\'%s\'] = new %s;', $typeName, $options['type_class']);

      $data[] = sprintf('$typeSpaces = array();');

      foreach($typeConfig as $spaceName => $modulesConfig)
      {
        $data[] = sprintf('$typeSpaces[\'%s\'] = new %s;', $spaceName, $options['space_class']);

        $data[] = sprintf('$spaceModules = array();');

        foreach($modulesConfig as $moduleKey => $moduleConfig)
        {
          $moduleClass = $options[$moduleConfig['is_project'] ? 'module_node_class' : 'module_base_class'];

          if ($moduleConfig['is_project'])
          {
            $moduleReceivers = sprintf('$modules[\'%s\'] = $projectModules[\'%s\'] = $spaceModules[\'%s\']', $moduleKey, $moduleKey, $moduleKey);
          }
          else
          {
            $moduleReceivers = sprintf('$modules[\'%s\'] = $spaceModules[\'%s\']', $moduleKey, $moduleKey);
          }
          
          $data[] = sprintf('%s = new %s(\'%s\', $typeSpaces[\'%s\'], %s);', $moduleReceivers, $moduleClass, $moduleKey, $spaceName, $this->getExportedModuleOptions($moduleKey, $moduleConfig));
        
          if ($moduleConfig['model'])
          {
            $data[] = sprintf('$modelModules[\'%s\'] = \'%s\';', $moduleConfig['model'], $moduleKey);
          }
        }

        $data[] = sprintf('$typeSpaces[\'%s\']->initialize(\'%s\', $types[\'%s\'], $spaceModules);', $spaceName, $spaceName, $typeName);
        
        $data[] = 'unset($spaceModules);';
      }

      $data[] = sprintf('$types[\'%s\']->initialize(\'%s\', $typeSpaces);', $typeName, $typeName);
      
      $data[] = 'unset($typeSpaces);';
    }

    $data[] = sprintf('$manager->load($types, $modules, $projectModules, $modelModules);');
    
    $data[] = 'unset($types, $modules, $projectModules, $modelModules);';

    $data[] = 'dmModule::setManager($manager);';
    
    $data[] = 'return $manager;';
    
    unset($this->config, $this->modules, $this->projectModules);

    return sprintf("<?php\n".
                 "// auto-generated by dmModuleManagerConfigHandler\n".
                 "// date: %s\n%s", date('Y/m/d H:i:s'), implode("\n", $data)
    );
  }
  
  protected function validate()
  {
    if (!isset($this->modules['main']))
    {
      $this->throwException('No main module');
    }
    
    if (!isset($this->config['Project']))
    {
      $this->throwException('No Project module type');
    }
    
    foreach($this->modules as $key => $module)
    {
      if (!$module['model'])
      {
        if (dmArray::get($module, 'has_page'))
        {
          $this->throwException('module %s has a page, but no model', $key);
        }
        if (dmArray::get($module, 'parent_key'))
        {
          $this->throwException('module %s has a parent, but no model', $key);
        }
      }
      else
      {
        if(!Doctrine::isValidModelClass($module['model']))
        {
          $this->throwException('module %s has a model that do not exist : %s', $key, $module['model']);
        }
        if($parentKey = dmArray::get($module, 'parent_key'))
        {
          if (!isset($this->modules[$parentKey]))
          {
            $this->throwException('module %s has a parent that do not exist : %s', $key, $parentKey);
          }
        }
      }
    }
  }
  
  protected function throwException($message)
  {
    $params = func_get_args();
    
    if (count($params) > 1)
    {
      ob_start();
      call_user_func_array('printf', $params);
      $message = ob_get_clean();
    }
    
    $fullMessage = 'Error in config/dm/modules.yml : '.$message;
    
    throw new sfConfigurationException($fullMessage);
  }

  protected function getExportedModuleOptions($key, $options)
  {
    return var_export($options, true);
  }
  
  protected function getModuleChildrenKeys($key)
  {
    $children = array();
    
    foreach($this->projectModules as $moduleConfig)
    {
      if ($moduleConfig['parent'] === $this->key)
      {
        $children[$otherModule->getKey()] = $otherModule;
      }
    }
  }

  protected function parse($configFiles)
  {
    // parse the yaml
    $config = self::getConfiguration($configFiles);
    
    $this->config = array();
    $this->modules = array();
    $this->projectModules = array();
    
    foreach($config as $typeName => $typeConfig)
    {
      $this->config[$typeName] = array();
      $isInProject = $typeName === 'Project';
      
      foreach($typeConfig as $spaceName => $spaceConfig)
      {
        $this->config[$typeName][$spaceName] = array();
        
        foreach($spaceConfig as $moduleKey => $moduleConfig)
        {
          $moduleKey = dmString::modulize($moduleKey);
          
          $moduleConfig = $this->fixModuleConfig($moduleKey, $moduleConfig, $isInProject);
          
          $this->modules[$moduleKey] = $moduleConfig;
          
          if ($moduleConfig['is_project'])
          {
            $this->projectModules[$moduleKey] = $moduleConfig;
          }
          
          $this->config[$typeName][$spaceName][$moduleKey] = $moduleConfig;
        }
      }
    }
    
    unset($config);
  }
  
  protected function fixModuleConfig($moduleKey, $moduleConfig, $isInProject)
  {
    /*
     * Extract plural from name
     * name | plural
     */
    if (!empty($moduleConfig['name']))
    {
      if (strpos($moduleConfig['name'], '|'))
      {
        list($moduleConfig['name'], $moduleConfig['plural']) = explode('|', $moduleConfig['name']);
      }
    }
    else
    {
      $moduleConfig['name'] = dmString::humanize($moduleKey);
    }
    
    if (empty($moduleConfig['model']))
    {
      $model = Doctrine::isValidModelClass($moduleKey) ? dmString::camelize($moduleKey) : false;
    }
    else
    {
      $model = $moduleConfig['model'];
    }
  
    if(isset($moduleConfig['views']))
    {
      throw new dmException('module views are deprecated');
    }
    
    $moduleOptions = array(
      'name' =>       (string) trim($moduleConfig['name']),
      'plural' =>     (string) trim(empty($moduleConfig['plural']) ? ($model ? dmString::pluralize($moduleConfig['name']) : $moduleConfig['name']) : $moduleConfig['plural']),
      'model' =>      $model,
      'credentials' => isset($moduleConfig['credentials']) ? trim($moduleConfig['credentials']) : null,
      'underscore'  => (string) dmString::underscore($moduleKey),
      'is_project'  => (boolean) dmArray::get($moduleConfig, 'project', $isInProject),
      'has_admin'  => (boolean) dmArray::get($moduleConfig, 'admin', $model || !$isInProject),
    );
    
    if ($moduleOptions['is_project'])
    {
      $moduleOptions = array_merge($moduleOptions, array(
        'parent_key' => dmArray::get($moduleConfig, 'parent') ? dmString::modulize(trim(dmArray::get($moduleConfig, 'parent'))) : null,
        'has_page'   => (boolean) dmArray::get($moduleConfig, 'page', false)
      ));
    }
    
    return $moduleOptions;
  }
  
  protected function processHierarchy()
  {
    foreach($this->config as $typeName => $typeConfig)
    {
      foreach($typeConfig as $spaceName => $spaceConfig)
      {
        foreach($spaceConfig as $moduleKey => $moduleConfig)
        {
          if (!$moduleConfig['is_project'])
          {
            continue;
          }
          
          $moduleConfig['children_keys'] = $this->getChildrenKeys($moduleKey);
          
          $moduleConfig['path_keys'] = $this->getPathKeys($moduleKey);
          
          $this->config[$typeName][$spaceName][$moduleKey] = $moduleConfig;
        }
      }
    }
  }
  
  protected function getChildrenKeys($moduleKey)
  {
    $childrenKeys = array();
    
    foreach($this->projectModules as $otherModuleKey => $otherModuleConfig)
    {
      if ($otherModuleConfig['parent_key'] === $moduleKey)
      {
        $childrenKeys[] = $otherModuleKey;
      }
    }
    
    return $childrenKeys;
  }
  
  protected function getPathKeys($moduleKey)
  {
    $pathKeys = array();

    $ancestorModuleKey = $moduleKey;
    while($ancestorModuleKey = $this->projectModules[$ancestorModuleKey]['parent_key'])
    {
      $pathKeys[] = $ancestorModuleKey;
    }

    return array_reverse($pathKeys);
  }
  
  /**
   * @see sfConfigHandler
   */
  static public function getConfiguration(array $configFiles)
  {
    return self::parseYamls($configFiles);
  }
}