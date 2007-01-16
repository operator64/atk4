<?php
// TODO: System_ProcessIO is created but not tested yet.
// Make sure to finish test-class and test with that

//
// This class provides a much more flexible and efficient interface
// to process creation and execution. It's similar to how popen
// works but allows handling multiple streams and supports
// exceptions


class System_ProcessIO_Exception extends BaseException { }
class System_ProcessIO extends AbstractModel {
    // allow us to execute commands in a flexible and easy way
    public $descriptorspec=array();
    public $args=array();
    public $cmd=null; 

    public $pipes=array();  // contain descriptions for stdin/out/err
    public $process=null;

    public $stderr='';
    public $exic_code=null;

    // Initialization and starting process
    function init(){
        parent::init();
        $this->descriptorspec=array(
                0 => array("pipe", "r"),  // stdin
                1 => array("pipe", "w"),  // stdout
                2 => array("pipe", "w")  // stderr ?? instead of a file
                );
    }
    function exec($cmd,$args=array()){
        // Arguments must be safe
        if(!is_array($args))$args=array($args);
        foreach($args as $val){
            if(!is_string($val))throw new BaseException("You must specify arguments as strings");
            $this->args[]=escapeshellarg($val);
        }

        // Command myst be safe
        $this->cmd=escapeshellcmd($cmd);

        // Now do the execution
        $this->execute_raw($this->cmd.' '.join(' ',$this->args));
        return $this;
    }
    protected function execute_raw($command){
        // This function just executes command and returns hash of descriptors.
        // Hash will have keys 'in', 'out' and 'err'

        $this->process = proc_open($command,$this->descriptorspec,$pipes);
        if(!is_resource($this->process)){
            throw new System_ProcessIO_Exception("Failed to execute");
        }

        $this->pipes['in'] =& $pipes[0];
        $this->pipes['out'] =& $pipes[1];
        $this->pipes['err'] =& $pipes[2];
        return $pipes;  // in case you need more streams redirected
    }

    // Basic input-output
    function write($str){
        // Sends string to process, but process will wait for more input.
        // always adds newline at the end
        if(!is_resource($this->pipes['in']))
            throw new System_ProcessIO_Exception("stdin is closed or haven't been opened. Cannot write to process");
        fwrite($this->pipes['in'],$str."\n");
        return $this;
    }
    function write_all($str){
        // Similar to write but will send EOF after sending text.
        // Also makes sure your list do not end with endline (because write
        // adds it)
        if(substr($str,-1,1)=="\n")$str=substr($str,0,-1);
        $this->write($str);
        $this->close('in');
        return $this;
    }
    function read_line($res='out'){
        // Reads one line of output. Careful - if no output is provided it this function
        // will be waiting.
        $str=fgets($this->pipes[$res]);
        if(substr($str,-1,1)=="\n")$str=substr($str,0,-1);
        return $str;
    }
    function read_all($res='out'){
        // Reads all output and returns. Closes stdout when EOF reached.
        $str='';
        while(!feof($this->pipes[$res])){
            $str.=fgets($this->pipes[$res],1024);
        }
        $this->close($res);
        if(substr($str,-1,1)!="\n")$str.="\n"
        return $str;
    }
    function read_stderr(){
        return $this->read_all('err');
    }

    // Closing IO and terminating
    function terminate($sig=null){
        // Terminates application without reading anything more.
        foreach($this->pipes as $key=>$res){
            $this->close($key);
        }
        proc_terminate($this->process,$sig);
    }
    function close($res=null){
        // This function will finish reading from in/err streams
        // and will close all streams. If you are doing your
        // own reading line-by-line or you want to terminate
        // application without reading all of it's output - 
        // use terminate() instead;
        //
        if(is_null($res)){
            $this->close('in');

            // Read remaining of stdout if not read
            if(!feof($this->pipes['out']))$out=$this->read_all();
            $out=$this->close('out');

            // Read stderr if anything is left there
            $this->stderr.=$this->read_stderr();
            $this->close('err');

            return $out;
        }else{
            if(is_resource($this->pipes[$res])){
                fclose($this->pipes[$res]);
                $this->pipes[$res]=null;
            }
        }
        $this->exit_code = proc_close($this->process);
    }

    // Dump
    function dump(){
        var_dump(proc_get_status($this->process));
    }

    // Misc
    function nice($nice){
        if(function_exists('proc_nice')){
            proc_nice($this->process,$nice);
        }
    }
}

class System_ProcessIO_Tester {
    public $scripts=array(
            'basic'=>array('exec','close'),
            'readwrite'=>array('exec','close','read_all','write_all'),
            'failure'=>array('exec','read_stderr'),
            'advanced'=>array('exec','write','read_line','terminate');
            );
    function test_basic(){
        $p=$this->add('System_ProcessIO')
            ->exec('/usr/bin/perl',array('-e','Hello|world\n'));

        $out=$p->close();

        $this->expects($out,"Hello|world\n");

        return $p;
    }
    function test_readwrite(){
        $p=$this->add('System_ProcessIO')
            ->exec('/usr/bin/sed','s/l/r/g')
            ->write_all('Hello world');
        $out=$p->read_all();

        $this->expects($out,'Herro worrd');

        return $p;
    }
    function test_failure(){
        $error='';
        try {
            $p=$this->add('System_ProcessIO')
                ->exec('/usr/non/existant/path','hello world')
                ->write_all('Hello world');
            $out=$p->close();
        }catch(System_ProcessIO_Exception $e){
            $error=$e->getMessage();
            $this->more_info('Expected exception caught',$e);
        }

        $this->expects($error);

        return $p;
    }
    function test_advanced(){
        $p=$this->add('System_ProcessIO')
            ->exec('/usr/bin/sed','s/l/r/g');

        $p->write('coca cola');
        $out=$p->read_line();
        $this->expects($out,'coca cora');

        $p->write('little love');
        $out=$p->read_line();
        $this->expects($out,'rittre rove');

        $this->terminate();

        return $p;
    }
}
?>