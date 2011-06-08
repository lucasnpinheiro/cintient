<?php
/*
 *
 *  Cintient, Continuous Integration made simple.
 *  Copyright (c) 2010, 2011, Pedro Mata-Mouros Fonseca
 *
 *  This file is part of Cintient.
 *
 *  Cintient is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  Cintient is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with Cintient. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * PhpDepend is a helper class for dealing with PHP_Depend third party
 * library. It specifically integrates with Project_Build, abstracts all
 * interactions with PHP_Depend and maintains a record of all high-level
 * collected metrics.
 *
 * @package     Build
 * @subpackage  SpecialTask
 * @author      Pedro Mata-Mouros Fonseca <pedro.matamouros@gmail.com>
 * @copyright   2010-2011, Pedro Mata-Mouros Fonseca.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU GPLv3 or later.
 * @version     $LastChangedRevision$
 * @link        $HeadURL$
 * Changed by   $LastChangedBy$
 * Changed on   $LastChangedDate$
 */
class Build_SpecialTask_PhpUnit extends Framework_DatabaseObjectAbstract implements Build_SpecialTaskInterface
{
  protected $_ptrProjectBuild; // Redundant but necessary for save()
  protected $_buildId;         // The project build ID serves as this instance's ID
  protected $_date;            // should practically coincide with the build's date
  protected $_version;

  public function __construct(Project_Build $build)
  {
    parent::__construct();
    $this->_ptrProjectBuild = $build;
    $this->_buildId = $build->getId();
    $this->_date = null;
    $this->_version = '';
  }


  public function __destruct()
  {
    parent::__destruct();
  }

  public function createReportFromJunit()
  {
    $junitReportFile = $this->getPtrProjectBuild()->getBuildDir() . CINTIENT_JUNIT_REPORT_FILENAME;
    if (!is_file($junitReportFile)) {
      SystemEvent::raise(SystemEvent::ERROR, "Junit file not found. [PID={$this->getProjectId()}] [BUILD={$this->getProjectBuildId()}] [FILE={$junitReportFile}]", __METHOD__);
      return false;
    }
    try {
      $xml = new SimpleXMLElement($junitReportFile, 0, true);
    } catch (Exception $e) {
      SystemEvent::raise(SystemEvent::ERROR, "Problems processing Junit XML file. [PID={$this->getProjectId()}] [BUILD={$this->getProjectBuildId()}]", __METHOD__);
      return false;
    }
    $xmls = $xml->children();
    foreach ($xmls as $node) {
      $imageFilename = '';
      $successes = array(); // assertions - failures
      $failures = array();
      $methodsNames = array();
      $classes = array();
      $methods = array();
      $classXml = call_user_func(function ($node) { // Access file testsuites directly (last level before testcases).
        if (isset($node->attributes()->file)) {
          return $node;
        } else {
          return f($node->children());
        }
      }, $node);
      $class = new TestClass();
      $class->setName($classXml->attributes()->name);
      $class->setFile((string)$classXml->attributes()->file);
      $class->setTests((string)$classXml->attributes()->tests);
      $class->setAssertions((string)$classXml->attributes()->assertions);
      $class->setFailures((string)$classXml->attributes()->failures);
      $class->setErrors((string)$classXml->attributes()->errors);
      $class->setTime((string)$classXml->attributes()->time);
      $class->setChartFilename(md5($this->getProjectId() . $this->getProjectBuildId() . $class->getFile()) . '.png');
      //
      // After call_user_func above we're exactly at the test class (file) root level,
      // with level 1 being the unit test (method of the original class)
      // and level 2 being the various datasets used in the test (each a
      // test case).
      //
      foreach ($classXml->children() as $methodXml) {
        $method = new TestMethod();
        $method->setName($methodXml->getName());
        $method->setTests((string)$methodXml->attributes()->tests);
        $method->setAssertions((string)$methodXml->attributes()->assertions);
        $method->setFailures((string)$methodXml->attributes()->failures);
        $method->setErrors((string)$methodXml->attributes()->errors);
        $method->setTime((string)$methodXml->attributes()->time);
        $methods[] = $method;

        $time = (float)$methodXml->attributes()->time * 1000; // to milliseconds
        $methodsNames[] = $methodXml->attributes()->name;
        $f = ((((float)$methodXml->attributes()->failures) * $time) / (float)$methodXml->attributes()->assertions);
        $successes[] = (float)$time - (float)$f;
        $failures[] = $f;
      }

      $chartFile = "{$this->getPtrProjectBuild()->getBuildDir()}{$class->getChartFilename()}";
      if (!is_file($chartFile)) {
        if (!Chart::unitTests($chartFile, $methodsNames, $successes, $failures)) {
          SystemEvent::raise(SystemEvent::ERROR, "Chart file for unit tests was not saved. [PID={$this->getProjectId()}] [BUILD={$this->getProjectBuildId()}]", __METHOD__);
        } else {
          SystemEvent::raise(SystemEvent::INFO, "Generated chart file for unit tests. [PID={$this->getProjectId()}] [BUILD={$this->getProjectBuildId()}]", __METHOD__);
        }
      }
      $class->setTestMethods($methods);
      $classes[] = $class;
      return $classes;
    }
  }

  public function preBuild()
  {
    SystemEvent::raise(SystemEvent::DEBUG, "Called.", __METHOD__);
    return true;
  }

  public function postBuild()
  {
    SystemEvent::raise(SystemEvent::DEBUG, "Called.", __METHOD__);
    //
    // Backup the original junit report file
    //
    if (!@copy($this->getPtrProjectBuild()->getPtrProject()->getReportsWorkingDir() . CINTIENT_JUNIT_REPORT_FILENAME, $this->getPtrProjectBuild()->getBuildDir() . CINTIENT_JUNIT_REPORT_FILENAME)) {
      SystemEvent::raise(SystemEvent::ERROR, "Could not backup original Junit XML file [PID={$this->getProjectId()}] [BUILD={$this->getProjectBuildId()}]", __METHOD__);
      return false;
    }
    return true;
  }

  public function getViewData()
  {
    $ret = array();
    $ret['project_buildJunit'] = $this->createReportFromJunit();
    return $ret;
  }

  /**
   * A slightly different version of the base _getCurrentSignature() is
   * needed, i.e., pointer to Project_Build is not to be considered.
   */
  private function _getCurrentSignature()
  {
    $arr = get_object_vars($this);
    $arr['_signature'] = null;
    unset($arr['_signature']);
    $arr['_ptrProjectBuild'] = null;
    unset($arr['_ptrProjectBuild']);
    return md5(serialize($arr));
  }

  /**
   * Getter for the project build ID
   */
  public function getProjectBuildId()
  {
    return $this->_ptrProjectBuild->getId();
  }

	/**
   * Getter for the project ID
   */
  public function getProjectId()
  {
    return $this->_ptrProjectBuild->getPtrProject()->getId();
  }

  public function init()
  {
    return true;
  }

  protected function _save($force = false)
  {
    /*
    if (!$this->hasChanged()) {
      if (!$force) {
        return false;
      }
      SystemEvent::raise(SystemEvent::DEBUG, "Forced object save.", __METHOD__);
    }

    if (!Database::beginTransaction()) {
      return false;
    }
    $sql = 'REPLACE INTO phpdepend' . $this->getProjectId()
         . ' (buildid, date, version, ahh, andc, calls, ccn, ccn2, cloc,'
         . ' clsa, clsc, eloc, fanout, leafs, lloc, loc, maxdit, ncloc,'
         . ' noc, nof, noi, nom, nop, roots)'
         . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $val = array(
      $this->getBuildId(),
      $this->getDate(),
      $this->getVersion(),
      $this->getAhh(),
      $this->getAndc(),
      $this->getCalls(),
      $this->getCcn(),
      $this->getCcn2(),
      $this->getCloc(),
      $this->getClsa(),
      $this->getClsc(),
      $this->getEloc(),
      $this->getFanout(),
      $this->getLeafs(),
      $this->getLloc(),
      $this->getLoc(),
      $this->getMaxDit(),
      $this->getNcloc(),
      $this->getNoc(),
      $this->getNof(),
      $this->getNoi(),
      $this->getNom(),
      $this->getNop(),
      $this->getRoots(),
    );

    if (!Database::execute($sql, $val)) {
      Database::rollbackTransaction();
      SystemEvent::raise(SystemEvent::ERROR, "Problems saving to db.", __METHOD__);
      return false;
    }

    if (!Database::endTransaction()) {
      SystemEvent::raise(SystemEvent::ERROR, "Something occurred while finishing transaction. The object might not have been saved.", __METHOD__);
      return false;
    }

    #if DEBUG
    SystemEvent::raise(SystemEvent::DEBUG, "Saved.", __METHOD__);
    #endif

    $this->resetSignature();
    return true;
    */
  }


  static private function _getObject(Resultset $rs, Project_Build $build)
  {
    $ret = new self($build);

    $ret->setDate($rs->getDate());
    $ret->setVersion($rs->getVersion());

    $ret->resetSignature();
    return $ret;
  }


  static public function install(Project $project)
  {
    /*
    $sql = "
CREATE TABLE IF NOT EXISTS phpdepend{$project->getId()} (
  buildid INTEGER PRIMARY KEY,
  date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version TEXT NOT NULL DEFAULT '" . CINTIENT_DATABASE_SCHEMA_VERSION . "',
  ahh REAL UNSIGNED NOT NULL DEFAULT 0.0,
  andc REAL UNSIGNED NOT NULL DEFAULT 0.0,
  calls INTEGER UNSIGNED NOT NULL DEFAULT 0,
  ccn INTEGER UNSIGNED NOT NULL DEFAULT 0,
  ccn2 INTEGER UNSIGNED NOT NULL DEFAULT 0,
  cloc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  clsa INTEGER UNSIGNED NOT NULL DEFAULT 0,
  clsc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  eloc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  fanout INTEGER UNSIGNED NOT NULL DEFAULT 0,
  leafs INTEGER UNSIGNED NOT NULL DEFAULT 0,
  lloc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  loc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  maxdit INTEGER UNSIGNED NOT NULL DEFAULT 0,
  ncloc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  noc INTEGER UNSIGNED NOT NULL DEFAULT 0,
  nof INTEGER UNSIGNED NOT NULL DEFAULT 0,
  noi INTEGER UNSIGNED NOT NULL DEFAULT 0,
  nom INTEGER UNSIGNED NOT NULL DEFAULT 0,
  nop INTEGER UNSIGNED NOT NULL DEFAULT 0,
  roots INTEGER UNSIGNED NOT NULL DEFAULT 0
);
";
    if (!Database::execute($sql)) {
      SystemEvent::raise(SystemEvent::ERROR, "Problems creating table. [TABLE={$project->getId()}]", __METHOD__);
      return false;
    }
    return true;
    */
  }


  static public function uninstall(Project $project)
  {
    /*
    $sql = "DROP TABLE phpdepend{$project->getId()}";
    if (!Database::execute($sql)) {
      SystemEvent::raise(SystemEvent::ERROR, "Couldn't delete table. [TABLE={$project->getId()}]", __METHOD__);
      return false;
    }
    return true;
    */
  }


  static public function getById(Project_Build $build, User $user, $access = Access::READ, array $options = array())
  {
    return new self($build);
    /*
    $ret = false;
    $access = (int)$access; // Unfortunately, no enums, no type hinting, no cry.
    $sql = 'SELECT pd.*'
         . ' FROM phpdepend' . $build->getProjectId() . ' pd, projectuser pu'
         . ' WHERE pu.projectid=?'
         . ' AND pu.userid=?'
         . ' AND pu.access & ?'
         . ' AND pd.buildid=?';
    $val = array($build->getProjectId(), $user->getId(), $access, $build->getId());
    if ($rs = Database::query($sql, $val)) {
      if ($rs->nextRow()) {
        $ret = self::_getObject($rs, $build);
      }
    }
    return $ret;
    */
  }
}