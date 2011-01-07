<?php
/*
 * Cintient, Continuous Integration made simple.
 * 
 * Copyright (c) 2011, Pedro Mata-Mouros <pedro.matamouros@gmail.com>
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 
 * . Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 * 
 * . Redistributions in binary form must reproduce the above
 *   copyright notice, this list of conditions and the following
 *   disclaimer in the documentation and/or other materials provided
 *   with the distribution.
 *   
 * . Neither the name of Pedro Mata-Mouros Fonseca, Cintient, nor
 *   the names of its contributors may be used to endorse or promote
 *   products derived from this software without specific prior
 *   written permission.
 *   
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDER AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 * 
 */

/**
 * 
 */
class ProjectBuild
{
  private $_id;           // the build's incremental ID
  private $_date;         // the build's date
  private $_label;        // the label on the build, also used to name the release package file
  private $_description;  // a user generated description text (prior or after the build triggered).
  private $_output;       // the integration builder's output collected
  private $_status;       // indicates: failure | no_release | release
  private $_project;      // the project ID goes into the table name - it's not an attribute
  private $_signature;    // Internal flag to control whether a save to database is required

  const STATUS_FAIL = 0;
  const STATUS_OK_WITHOUT_PACKAGE = 1;
  const STATUS_OK_WITH_PACKAGE = 2;

  /**
   * Magic method implementation for calling vanilla getters and setters. This
   * is rigged to work only with private/protected non-static class variables
   * whose nomenclature follows the Zend Coding Standard.
   * 
   * @param $name
   * @param $args
   */
  public function __call($name, $args)
  {
    if (strpos($name, 'get') === 0) {
      $var = '_' . lcfirst(substr($name, 3));
      return $this->$var;
    } elseif (strpos($name, 'set') === 0) {
      $var = '_' . lcfirst(substr($name, 3));
      $this->$var = $args[0];
      return true;
    }
    return false;
  }
  
  public function __construct(Project $project)
  {
    $this->_project = $project;
    $this->_id = null;
    $this->_date = null;
    $this->_label = '';
    $this->_description = '';
    $this->_output = '';
    $this->_status = self::STATUS_FAIL;
    $this->_signature = null;
  }
  
  public function __destruct()
  {
    $this->_save();
  }
  
  public function createReportFromJunit()
  {
    $junitReportFile = $this->getReportsDir() . CINTIENT_JUNIT_REPORT_FILENAME;
    if (!is_file($junitReportFile)) {
      SystemEvent::raise(SystemEvent::ERROR, "Junit file not found. [PID={$this->getProject()->getId()}] [BUILD={$this->getId()}]", __METHOD__);
      return false;
    }
    //
    // Access file testsuites directly (last level before testcases).
    // This can't be a closure because of its recursiveness.
    //
    function f($node) {
      if (isset($node->attributes()->file)) {
        return $node;
      } else {
        return f($node->children());
      }
    }
    try {
      $xml = new SimpleXMLElement($junitReportFile, 0, true);
    } catch (Exception $e) {
      SystemEvent::raise(SystemEvent::ERROR, "Problems processing Junit XML file. [PID={$this->getProject()->getId()}] [BUILD={$this->getId()}]", __METHOD__);
      return false;
    }
    $xmls = $xml->children();
    foreach ($xmls as $node) {
      $successes = array(); // assertions - failures
      $failures = array();
      $methods = array();
      $classXml = f($node);
      //
      // After f() we're exactly at the test class (file) root level,
      // with level 1 being the unit test (method of the original class)
      // and level 2 being the various datasets used in the test (each a
      // test case).
      //
      foreach ($classXml->children() as $methodXml) {
        $time = (float)$methodXml->attributes()->time * 1000; // to milliseconds
        $methods[] = $methodXml->attributes()->name;
        $f = ((((float)$methodXml->attributes()->failures) * $time) / (float)$methodXml->attributes()->assertions);
        $successes[] = (float)$time - (float)$f;
        $failures[] = $f;
      }

      $chartWidth = 700;
      $chartHeight = 25 * count($methods) + 60;
      
      
      return true;
    }
  }
  
  public function delete()
  {
    $sql = "DROP TABLE projectbuild{$this->getProject()->getId()}";
    if (!Database::execute($sql)) {
      SystemEvent::raise(SystemEvent::ERROR, "Couldn't delete project build table. [TABLE={$this->getProject()->getId()}]", __METHOD__);
      return false;
    }
    return true;
  }
  
  private function _getCurrentSignature()
  {
    $arr = get_object_vars($this);
    $arr['_signature'] = null;
    unset($arr['_signature']);
    return md5(serialize($arr));
  }
  
  public function getReportsDir()
  {
    return $this->getProject()->getReportsWorkingDir() . $this->getId() . '/';
  }
  
  public function init()
  {
    //
    // Get the ID
    //
    if (!$this->_save()) {
      return false;
    }
    //
    // Create this build's report dir, backing up an existing one
    //
    if (is_dir($this->getReportsDir())) {
      $backupOldBuildReportDir = $this->getReportsDir() . '_old_' . uniqid() . '/';
    }
    if (!mkdir($this->getReportsDir(), DEFAULT_DIR_MASK, true)) {
      SystemEvent::raise(SystemEvent::ERROR, "Couldn't create report dir for build. [PID={$this->getProject()->getId()}] [DIR={$this->getReportsDir()}] [BUILD={$this->getId()}]", __METHOD__);
      return false;
    }
    //
    // Backup the original junit report file
    // TODO: only if unit tests were comissioned!!!!
    //
    //if (UNIT_TESTES_WERE_DONE) {
      if (!@rename($this->getProject()->getReportsWorkingDir() . CINTIENT_JUNIT_REPORT_FILENAME, $this->getReportsDir() . CINTIENT_JUNIT_REPORT_FILENAME)) {
        SystemEvent::raise(SystemEvent::ERROR, "Could not backup original Junit XML file [PID={$this->getProject()->getId()}] [BUILD={$this->getId()}]", __METHOD__);
      }
    //}
    return true;
  }
  
  private function _save($force=false)
  {
    if ($this->_getCurrentSignature() == $this->_signature && !$force) {
      SystemEvent::raise(SystemEvent::DEBUG, "Save called, but no saving is required.", __METHOD__);
      return false;
    }
    if (!Database::beginTransaction()) {
      return false;
    }
    $sql = 'INSERT INTO projectbuild' . $this->getProject()->getId()
         . ' (label, description, output, status)'
         . ' VALUES (?,?,?,?)';
    $val = array(
      $this->getLabel(),
      $this->getDescription(),
      $this->getOutput(),
      $this->getStatus(),
    );
    if (!($id = Database::insert($sql, $val)) || !is_numeric($id)) {
      Database::rollbackTransaction();
      SystemEvent::raise(SystemEvent::ERROR, "Problems saving to db.", __METHOD__);
      return false;
    }
    $this->setId($id);
    
    if (!Database::endTransaction()) {
      SystemEvent::raise(SystemEvent::ERROR, "Something occurred while finishing transaction. The project build might not have been saved. [PID={$this->getProject()->getId()}]", __METHOD__);
      return false;
    }
    #if DEBUG
    SystemEvent::raise(SystemEvent::DEBUG, "Saved project build. [PID={$this->getProject()->getId()}]", __METHOD__);
    #endif
    $this->updateSignature();
    return true;
  }
  
  public function updateSignature()
  {
    $this->setSignature($this->_getCurrentSignature());
  }
  
  static public function getListByProject($project, $user, $access = Access::READ, array $options = array())
  {
    isset($options['sort'])?:$options['sort']=Sort::DATE_DESC;
    isset($options['pageStart'])?:$options['pageStart']=0;
    isset($options['pageLength'])?:$options['pageLength']=CINTIENT_BUILDS_PAGE_LENGTH;
    
    $ret = false;
    $access = (int)$access; // Unfortunately, no enums, no type hinting, no cry.
    $sql = 'SELECT pb.*'
         . ' FROM projectbuild' . $project->getId() . ' pb, projectuser pu'
         . ' WHERE pu.projectid=?'
         . ' AND pu.userid=?'
         . ' AND pu.access & ?';
    if ($options['sort'] != Sort::NONE) {
      $sql .= ' ORDER BY';
      switch ($options['sort']) {
        case Sort::DATE_ASC:
          $sql .= ' pb.id ASC';
          break;
        case Sort::DATE_DESC:
          $sql .= ' pb.id DESC';
      }
    }
    $sql .= ' LIMIT ?, ?';
    $val = array($project->getId(), $user->getId(), $access, $options['pageStart'], $options['pageLength']);
    if ($rs = Database::query($sql, $val)) {
      $ret = array();
      while ($rs->nextRow()) {
        $projectBuild = self::_getObject($rs, $project);
        $ret[] = $projectBuild;
      }
    }
    return $ret;
  }
  
  static private function _getObject(Resultset $rs, Project $project)
  {
    $ret = new ProjectBuild($project);
    $ret->setId($rs->getId());
    $ret->setDate($rs->getDate());
    $ret->setLabel($rs->getLabel());
    $ret->setDescription($rs->getDescription());
    $ret->setOutput($rs->getOutput());
    $ret->setStatus($rs->getStatus());
    
    $ret->updateSignature();
    return $ret;
  }
  
  static public function install($projectId)
  {
    $sql = <<<EOT
CREATE TABLE IF NOT EXISTS projectbuild{$projectId} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date DATETIME DEFAULT CURRENT_TIMESTAMP,
  label VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  output TEXT NOT NULL DEFAULT '',
  status TINYINT UNSIGNED DEFAULT 0
);
EOT;
    if (!Database::execute($sql)) {
      SystemEvent::raise(SystemEvent::ERROR, "Problems creating table. [TABLE={$projectId}]", __METHOD__);
      return false;
    }
    return true;
  }
  
  static public function uninstall($projectId)
  {
    $sql = "DROP TABLE projectbuild{$projectId}";
    if (!Database::execute($sql)) {
      SystemEvent::raise(SystemEvent::ERROR, "Couldn't delete project build table. [TABLE={$projectId}]", __METHOD__);
      return false;
    }
    return true;
  }
}