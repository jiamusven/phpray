<?php
/**
 * Created by PhpStorm.
 * User: panzd
 * Date: 15/2/17
 * Time: 下午4:01
 */

namespace PHPRay\Controller;

use PHPRay\Util\Auth;
use PHPRay\Util\ErrorHandler;
use PHPRay\Util\Functions;
use PHPRay\Util\LogInterceptorFactory;
use PHPRay\Util\RunkitLogInterceptor;
use PHPRay\Util\Project;
use PHPRay\Util\ReflectionUtil;
use Nette\Reflection\ClassType;
use PHPRay\Util\Profiler;

/**
 * Class MainController
 * @package PHPRay\Controller
 * @author zhandong.pan <zhandong.pan@funplus.cn>
 */
class MainController
{
    /**
     * @return array|string
     */
    public function getProjects()
    {
        if (!Auth::isValidUser()) {
            return "unauthed";
        }

        $projects = Project::getProjects(Auth::getUser());

        $ret = array();
        foreach ($projects as $project) {
            $ret[] = $project["name"];
        }

        return $ret;
    }

    public function getFileTree()
    {
        if (!Auth::isValidUser()) return "unauthed";

        $project = $this->getProject();
        return Functions::treeDir($project["src"]);
    }

    public function getClassesAndMethods()
    {
        if (!Auth::isValidUser()) return "unauthed";

        $project = $this->initProject();
        $this->includeProjectFile($project);

        $path = $project["src"] . DIRECTORY_SEPARATOR . $_POST['fileName'];

        return ReflectionUtil::fetchClassesAndMethodes($path);
    }

    public function getCode()
    {
        if (!Auth::isValidUser()) {
            return "unauthed";
        }
        $file = $_POST["file"];
        return Functions::sliceCode($file, $_POST["line"], 7);
    }

    /**
     *
     * @return array
     */
    public function getTestCode()
    {
        if (!Auth::isValidUser()) {
            return "unauthed";
        }

        $project = $this->initProject();
        $this->includeProjectFile($project);

        $className = $_POST["className"];
        $methodName = $_POST["methodName"];

        $class = new ClassType($className);
        $method = $class->getMethod($methodName);

        return array(
            'classCode' => ReflectionUtil::getClassTestCode($class),
            'methodCode' => ReflectionUtil::getMethodTestCode($method, $className)
        );
    }

    public function runTest()
    {
        if (!Auth::isValidUser()) {
            return "unauthed";
        }

        if (function_exists("xdebug_disable")) {
            xdebug_disable();
        }

        $project = $this->initProject();
        Project::interceptLogs($project);
        $this->includeProjectFile($project);

        if (array_key_exists('className', $_POST) && !empty($_POST['className'])) {
            ReflectionUtil::publicityAllMethods($_POST['className']);
        }

        error_reporting(E_ALL);
        ini_set("display_errors", 1);

        $errorHandler = new ErrorHandler();
        $errorHandler->enable();

        ob_start();

        $ret = null;
        $profileData = null;
        $profiler = new Profiler($project);
        $start = Functions::getMillisecond();
        $exception = null;
        try {
            $instance = null;

            $classCode = trim($_POST['classCode']);

            $profiler->enable();

            if ($classCode) {
                $instance = eval($_POST['classCode']);
            }

            $ret = eval($_POST["methodCode"]);
        } catch (\Exception $e) {
            $errorHandler->catchException($e);
            $exception = $e;
        }

        Project::shutdownProject($project, $exception);

        $profileData = $profiler->disable($profileData);
        $output = ob_get_clean();
        $elapsed = Functions::getMillisecond() - $start;
        $errorHandler->catchTheLastError();

        return array(
            'return' => ReflectionUtil::watch($ret),
            'output' => $output,
            'errors' => $errorHandler->getErrors(),
            'elapsed' => $elapsed,
            'profileData' => $profileData,
            'logs' => LogInterceptorFactory::getLogInterceptor()->getLogs()
        );
    }
    
    private function initProject()
    {
        if (array_key_exists('project', $_REQUEST)) {
            return Project::initProject(Auth::getUser(), $_REQUEST['project']);
        }

        return null;
    }

    private function getProject()
    {
        if (array_key_exists('project', $_REQUEST)) {
            return Project::getProject(Auth::getUser(), $_REQUEST['project']);
        }

        return null;
    }

    private function includeProjectFile($project)
    {
        if (!empty($project) && array_key_exists('fileName', $_POST)) {
            Project::includeProjectFile($project, $_POST['fileName']);
        }
    }
}