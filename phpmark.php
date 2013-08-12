<?php

error_reporting(-1);
ini_set('display_errors', true);

header('Content-Type: text/plain');

class PHPMark
{
    private $runs = 1000;
    private $tests = array();
    private $steps = array();
    
    private $results = array();
    
    public function __construct()
    {
        $arguments = func_get_args();
        $this->steps = array_filter($arguments, function($value)
        {
            return is_string($value);
        });
    }
    
    public function setRuns($runs)
    {
        $this->runs = $runs;
    }
    
    public function getRuns()
    {
        return $this->runs;
    }
    
    public function isStepDefined($step)
    {
        return in_array($step, $this->steps);
    }
    
    public function add($name)
    {
        $test = new Test($this);
        
        $this->tests[$name] = $test;
        
        return $test;
    }
    
    public function isSetup()
    {
        $needed = count($this->steps) * count($this->tests);
        $steps = 0;
        
        foreach ($this->tests as $test)
        {        
            foreach ($this->steps as $step)
            {            
                if ($test->isStepDefined($step))
                {
                    $steps++;
                }
            }
        }
                
        if ($needed !== $steps)
        {
            throw new Exception('The benchmark is incomplete!');
        }
        
        return $needed === $steps;
    }
    
    public function benchmark($testName, $stepName, $run, Closure $test, array $arguments = array(), &$outResult = null)
    {
        $start = array(
            'time' => time(),
            'microtime' => microtime(true),
            'memory' => memory_get_usage()
        );
        
        $result = call_user_func_array($test, $arguments);
        $outResult = $result;
        
        $end = array(
            'time' => time(),
            'microtime' => microtime(true),
            'memory' => memory_get_usage()
        );
        
        if (!array_key_exists($testName, $this->results))
        {
            $this->results[$testName] = array();
        }
        
        if (!array_key_exists($stepName, $this->results[$testName]))
        {
            $this->results[$testName][$stepName] = array();
        }
        
        $this->results[$testName][$stepName][$run] = array(
            'start' => $start,
            'end' => $end,
            'arguments' => $arguments,
            'script' => $this->dump($test),
            'result' => $result
        );
    }
    
    public function dump(Closure $c)
    {
        $str = 'function (';
        $r = new ReflectionFunction($c);
        $params = array();
        foreach($r->getParameters() as $p) {
            $s = '';
            if($p->isArray()) {
                $s .= 'array ';
            } else if($p->getClass()) {
                $s .= $p->getClass()->name . ' ';
            }
            if($p->isPassedByReference()){
                $s .= '&';
            }
            $s .= '$' . $p->name;
            if($p->isOptional()) {
                $s .= ' = ' . var_export($p->getDefaultValue(), TRUE);
            }
            $params []= $s;
        }
        $str .= implode(', ', $params);
        $str .= '){' . PHP_EOL;
        $lines = file($r->getFileName());
        for($l = $r->getStartLine(); $l < $r->getEndLine(); $l++) {
            $str .= $lines[$l];
        }
        return $str;
    }
    
    public function run()
    {
        if ($this->isSetup())
        {
            foreach ($this->tests as $name => $test)
            {
                if (($initializer = $test->getInitializer()))
                {
                    $this->benchmark($name, 'initializer', 0, $initializer, array(), $arguments);
                }
                
                foreach ($this->steps as $step)
                {
                    for ($run = 0; $run < $this->runs; $run++)
                    {
                        $this->benchmark($name, $step, $run + 1, $test->getStep($step), $arguments ?: array());
                    }
                }
            }
            
            return $this->results;
        }
    }
}

class Test
{   
    private $initializer = null;
    
    private $testRunner;
    
    private $steps = array();
    
    public function __construct(PHPMark $runner)
    {
        $this->testRunner = $runner;
    }
    
    public function initialize(Closure $initializer)
    {
        $this->initializer = $initializer;
    }
    
    public function __call($method, array $arguments = array())
    {
        if (!$this->testRunner->isStepDefined($method))
        {
            throw new InvalidArgumentException('Runner does not have ' . $method . ' as a registered step.');
        }
    
        if (empty($arguments) || !$arguments[0] instanceof Closure)
        {
            throw new InvalidArgumentException('Step is required and has to be an instance of Closure.');
        }
        
        $test = $arguments[0];
        
        $this->steps[$method] = $test;
    }
    
    public function isStepDefined($step)
    {
        return array_key_exists($step, $this->steps);
    }
    
    public function getInitializer()
    {
        return $this->initializer;
    }
    
    public function getStep($step)
    {
        return $this->steps[$step];
    }
}

$mark = new PHPMark('loop');

$mark->setRuns(5);

$while = $mark->add('while');
$while->initialize(function()
{
    return array(1, 'two');
});

$while->loop(function($count, $counter)
{
});

var_dump( $mark->run() );