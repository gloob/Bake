<?php

/****************************************************************************/
define('BAKE_VERSION', '0.1');
define('BAKE_URL', 'http://bake.gwynne.dyndns.org');

/****************************************************************************/
class Baker
{
    /************************************************************************/
    public static $verbosity = 0;
    
    /************************************************************************/
    protected $project_id = NULL;
    protected $project_name = NULL;
    protected $languages = NULL;
    protected $min_version = NULL;
    
    protected $top_level_text = NULL;
    protected $current_subdir = NULL;

    protected $finders = array();
    protected $subdirectories = array();
    
    protected $options = array();
    
    protected $output_path = NULL;
    
    /************************************************************************/
    protected static function _require_type($value, $type)
    {
        if (gettype($value) !== $type) {
            self::_error("Programmer error (bad parameter type), dying now.");
        }
    }
    
    /************************************************************************/
    protected static function _makecmakearray($value)
    {
        if (is_string($value)) {
            return $value;
        } else if (is_array($value)) {
            return implode(' ', $value);
        } else {
            self::_error("Programmer error (bad array type), dying now.");
        }
    }
    
    /************************************************************************/
    protected static function _error($message)
    {
        fprintf(STDERR, "ERROR: %s\n", $message);
        exit(1);
    }
    
    /************************************************************************/
    protected static function _warning($message)
    {
        fprintf(STDERR, "WARNING: %s\n", $message);
    }
    
    /************************************************************************/
    protected static function _status($message)
    {
        if (self::$verbosity > -1) {
            fprintf(STDOUT, "%s\n", $message);
        }
    }

    /************************************************************************/
    protected static function _debug($message)
    {
        if (self::$verbosity > 0) {
            fprintf(STDOUT, "DEBUG: %s\n", $message);
        }
    }

    /************************************************************************/
    protected function _addText($text)
    {
        if ($this->current_subdir === NULL) {
            $this->top_level_text .= $text;
        } else {
            $this->subdirectories[$this->current_subdir] .= $text;
        }
    }
    
    /************************************************************************/
    public function __construct($output_path)
    {
        self::_require_type($output_path, 'string');
        self::_debug("Setting up to output files in \"{$output_path}\".");
        
        if (!is_dir($output_path) || !is_writable($output_path)) {
            self::_error("Output path {$output_path} isn't a writable directory.");
        }
        $this->output_path = realpath($output_path);
    }
    
    /************************************************************************/
    public function startProject($identifier, $name, $languages = array('C', 'CXX'))
    {
        self::_require_type($identifier, 'string');
        self::_require_type($name, 'string');
        self::_require_type($languages, 'array');
        self::_debug("Starting new project \"{$identifier} ({$name})\".");
        
        $this->project_id = $identifier;
        $this->project_name = $name;
        $this->languages = $languages;
    }
    
    /************************************************************************/
    public function requireCMakeVersion($version)
    {
        self::_require_type($version, 'string');
        self::_debug("Setting minimum CMake version to \"{$version}\".");
        
        $this->min_version = $version;
    }
    
    /************************************************************************/
    public function generateFindCommand($name, $data)
    {
        self::_require_type($name, 'string');
        self::_require_type($data, 'array');
        self::_debug("Creating find command for package \"{$name}\".");
        
        $command_filename = "Find{$name}.cmake";
        $command_text = "SET({$name}_FOUND FALSE)\n";
        
        if (isset($data['configure_options'])) {
            foreach ($data['configure_options'] as $flag => $var) {
                $this->options[] = array('flag' => $flag, 'var' => $var);
            }
        }
        if (in_array('executable', $data['type'])) {
            $command_text .= "FIND_PROGRAM({$name}_EXECUTABLE NAMES " . implode(' ', $data['exec_binary_names']) .
                             " DOC \"path to {$name} executable\")\n";
            $command_text .= "IF({$name}_EXECUTABLE)\n";
            $command_text .= "\tSET({$name}_FOUND TRUE)\n";
            if (isset($data['exec_version_check_cmd'])) {
                $command_text .= <<<VERCHECK
    EXECUTE_PROCESS(COMMAND {$data['exec_version_check_cmd']}
        RESULT_VARIABLE {$name}_version_result
        OUTPUT_VARIABLE {$name}_version_output
        ERROR_VARIABLE {$name}_version_error
        OUTPUT_STRIP_TRAILING_WHITESPACE
    )

    IF(NOT {$name}_version_result EQUAL {$data['exec_version_check_status']})
        MESSAGE(FATAL_ERROR "Command \\"{$data['exec_version_check_cmd']}\\" failed with output:\\n\${{$name}_version_error}")
    ELSE(NOT {$name}_version_result EQUAL {$data['exec_version_check_status']})
        STRING(REGEX REPLACE "{$data['exec_version_check_regex']}" "\\\\1" {$name}_VERSION "\${{$name}_version_output}")
    ENDIF(NOT {$name}_version_result EQUAL {$data['exec_version_check_status']})

VERCHECK;
            }
            $command_text .= $data['exec_run_macro'] . "\n";
            $command_text .= "ENDIF({$name}_EXECUTABLE)\n";
        }
        $this->finders[$command_filename] = $command_text;
    }
    
    /************************************************************************/
    public function enterSubdirectory($name)
    {
        self::_require_type($name, 'string');
        self::_debug("Switching to subdirectory \"{$this->current_subdir}\".");

        $this->current_subdir = $name;
        if ($this->current_subdir !== NULL && !isset($this->subdirectories[$this->current_subdir])) {
            $this->subdirectories[$this->current_subdir] = '';
        }
    }
    
    /************************************************************************/
    public function addLibrary($name, $type, $sources)
    {
        self::_require_type($name, 'string');
        self::_require_type($type, 'string');
        self::_debug("Appending library \"{$name}\" in directory {$this->current_subdir}.");
        
        $this->_addText("ADD_LIBRARY({$name} {$type} " . self::_makecmakearray($sources) . ")\n");
    }
    
    /************************************************************************/
    public function addExecutable($name, $sources)
    {
        self::_require_type($name, 'string');
        self::_debug("Appending executable \"{$name}\" in directory {$this->current_subdir}.");
        
        $this->_addText("ADD_EXECUTABLE({$name} " . self::_makecmakearray($sources) . ")\n");
    }
    
    /************************************************************************/
    public function addCompilerFlags($name, $flags)
    {
        self::_require_type($name, 'string');
        self::_debug("Appending compiler flags \"{$flags}\" in directory {$this->current_subdir}.");
        
        $this->_addText("SET({$name}_compile_flags \${{$name}_compile_flags} " . self::_makecmakearray($flags) . ")\n");
        $this->_addText("SET_TARGET_PROPERTIES({$name} PROPERTIES COMPILE_FLAGS \${{$name}_compile_flags})\n");
    }
    
    /************************************************************************/
    public function targetLinkLibraries($name, $flags)
    {
        self::_require_type($name, 'string');
        self::_debug("Appending linker flags \"{$flags}\" in directory {$this->current_subdir}.");
        
        $this->_addText("TARGET_LINK_LIBRARIES({$name} " . self::_makecmakearray($flags) . ")\n");
    }
    
    /************************************************************************/
    public function findPackage($name, $version_check = NULL, $required = FALSE)
    {
        self::_require_type($name, 'string');
        self::_require_type($version_check, is_null($version_check) ? 'null' : 'string');
        self::_require_type($required, 'boolean');
        self::_debug("Appending FIND_PACKAGE({$name}) in directory {$this->current_subdir}.");
        
        $this->_addText("FIND_PACKAGE({$name}" . ($required ? " REQUIRED" : "") . ")\n");
        if (!is_null($version_check)) {
            $this->_addText(<<<VCHK
IF(NOT \${{$name}_VERSION} {$version_check})
    MESSAGE(FATAL_ERROR "Package \\"{$name}\\" too old (requires \\"{$version_check}\\")")
ENDIF(NOT \${{$name}_VERSION} {$version_check})

VCHK
            );
        }
    }
    
    /************************************************************************/
    public function literalText($text)
    {
        self::_require_type($text, 'string');
        self::_debug("Appending literal text in directory {$this->current_subdir}.");
        
        $this->_addText(trim($text) . "\n");
    }
    
    /************************************************************************/
    protected function _genTopCMakeLists()
    {
        $top_CMakeLists = '';
        if ($this->min_version !== NULL) {
            $top_CMakeLists .= "CMAKE_MINIMUM_REQUIRED(VERSION {$this->min_version} FATAL_ERROR)\n\n";
        }
        $top_CMakeLists .= "PROJECT({$this->project_id})\n\n";
        if (count($this->finders) > 0) {
            $top_CMakeLists .= 'SET(CMAKE_MODULE_PATH ${CMAKE_MODULE_PATH} "${CMAKE_SOURCE_DIR}/CMake")' . "\n\n";
        }
        foreach ($this->languages as $lang) {
            $top_CMakeLists .= "ENABLE_LANGUAGE({$lang})\n";
        }
        $top_CMakeLists .= "\n{$this->top_level_text}\n";
        foreach ($this->subdirectories as $dir => $text) {
            $top_CMakeLists .= "ADD_SUBDIRECTORY({$dir})\n";
        }
        return $top_CMakeLists;
    }

    /************************************************************************/
    protected function _genConfigure($now)
    {
        $options_array = "package_options=(\n";
        foreach ($this->options as $n => $option) {
            $n1 = $n * 2;
            $n2 = $n1 + 1;
            $options_array .= "\t[$n1]='{$option['flag']}'\t\t[$n2]='{$option['var']}'\n";
        }
        $options_array .= ")\n";
        $configure_template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'configure.in');
        $configure_text = str_replace(
            array('@@bake_version@@', '@@bake_url@@', '@@now@@', '@@package_options@@', '@@project_name@@'),
            array(BAKE_VERSION, BAKE_URL, $now, $options_array, $this->project_name),
            $configure_template);
        return $configure_text;
    }
    
    /************************************************************************/
    public function emit()
    {
        if (is_null($this->project_id)) {
            self::error("No project set!");
        }
        
        $now = gmdate('h:i:s m/d/Y T');
        $header = <<<CMAKE
# This file was generated by Bake at {$now}.
# Do not edit this file directly.


CMAKE;
        // Generate the top-level CMakeLists
        $top_CMakeLists = $this->_genTopCMakeLists();
        
        // Generate the configure script
        $configure_text = $this->_genConfigure($now);
        
        // Output the generated files
        self::_status("Writing top-level CMakeLists.txt.");
        if (file_put_contents($this->output_path . DIRECTORY_SEPARATOR . "CMakeLists.txt", $header . $top_CMakeLists) === FALSE) {
            self::_error("Failed writing to {$this->output_path}/CMakeLists.txt.");
        }
        if (count($this->finders) > 0) {
            self::_status("Creating modules directory.");
            $mods_dir = $this->output_path . DIRECTORY_SEPARATOR . 'CMake';
            @mkdir($mods_dir);
            if (!is_dir($mods_dir) || !is_writable($mods_dir)) {
                self::_error("Failed creating or writing to {$mods_dir}.");
            }
            foreach ($this->finders as $filename => $text) {
                self::_status("Writing {$filename} finder.");
                if (file_put_contents($mods_dir . DIRECTORY_SEPARATOR . $filename, $header . $text) === FALSE) {
                    self::_error("Failed writing to {$mods_dir}/{$filename}.");
                }
            }
        }
        foreach ($this->subdirectories as $dir => $text) {
            self::_status("Writing CMakeLists.txt for {$dir}.");
            if (file_put_contents($this->output_path . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . "CMakeLists.txt", $header . $text) === FALSE) {
                self::_error("Failed writing to {$this->output_path}/{$dir}/CMakeLists.txt.");
            }
        }
        self::_status("Writing configure.");
        if (file_put_contents($this->output_path . DIRECTORY_SEPARATOR . 'configure', $configure_text) === FALSE) {
            self::_error("Failed writing to {$this->output_path}/configure.");
        }
        if (@chmod($this->output_path . DIRECTORY_SEPARATOR . 'configure', 0755) === FALSE) {
            self::_warning("Failed making {$this->output_path}/configure executable.");
        }
        
        self::_status("All files generated successfully.");
    }
}

/****************************************************************************/
function LoadBake($args)
{
    $bv = BAKE_VERSION;
    $bu = BAKE_URL;

    $help = <<<HELP
Bake {$bv}

Bake is a wrapper generator for the CMake buildsystem.

Usage:
    php {$args[0]} [OPTIONS]

Options:
    -h/--help                   Show this help and exit.
    -v/--version                Show version and exit.
    --license                   Display Bake's license and exit.
    -o DIR/--output-path=DIR    Set the destination for all output files and
                                directories. Default: .
    --clean                     Clean generated files. Overrides --generate.
    --generate                  Generate files. Overrides --clean.
    -q/--quiet                  Suppress status messages.
    -d/--debug                  Give lots and lots of extra status messages.

HELP;

    $version = <<<VERSION
Bake {$bv}
{$bu}
Copyright (c) 2009-2010, Gwynne Raskind
All rights reserved.

All code generated by Bake is placed under the license of the project which
distributes it, or Bake's own license, whichever is less permissive. Bake
itself is distributed under the two-clause BSD License. Call Bake with the
--license option for more information.

VERSION;
    
    $license = <<<LICENSE
Bake is distributed under the terms of the two-clause BSD License (text
follows). Any code generated by Bake is distributed under the terms of either
the project which distributes it, or Bake's license, whichever is less
permissive.

Copyright (c) 2009-2010, Gwynne Raskind
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY Gwynne Raskind ''AS IS'' AND ANY EXPRESS OR
IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO
EVENT SHALL Gwynne Raskind BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR
BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER
IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.

LICENSE;
    
    $output_path = '.';
    $action = 'generate';
    
    for ($i = 1; $i < count($args); ++$i) {
        if (strncmp($args[$i], '-o', 2) === 0) {
            if (strlen($args[$i]) > 2) {
                $output_path = substr($args[$i], 2);
            } else if ($i < (count($args) - 1)) {
                $output_path = $args[++$i];
            } else {
                die("-o requires an argument.\n");
            }
        } else if (strncmp($args[$i], '--output-path=', strlen('--output_path=')) === 0) {
            $output_path = substr($args[$i], strlen('--output_path='));
        } else if (strcmp($args[$i], '--clean') === 0) {
            $action = 'clean';
        } else if (strcmp($args[$i], '--generate') === 0) {
            $action = 'generate';
        } else if (strcmp($args[$i], '--quiet') === 0 || strcmp($args[$i], '-q') === 0) {
            Baker::$verbosity = -1;
        } else if (strcmp($args[$i], '--debug') === 0 || strcmp($args[$i], '-d') === 0) {
            Baker::$verbosity = 1;
        } else if (strcmp($args[$i], '--help') === 0 || strcmp($args[$i], '-h') === 0) {
            print $help;
            exit(0);
        } else if (strcmp($args[$i], '--version') === 0 || strcmp($args[$i], '-v') === 0) {
            print $version;
            exit(0);
        } else if (strcmp($args[$i], '--license') === 0) {
            print $license;
            exit(0);
        } else {
            die("Unrecognized option {$args[$i]}. Try {$args[0]} --help.\n");
        }
    }
    
    if ($action === 'clean') {
        $output_path = realpath($output_path);
        if (!is_string($output_path)) {
            die("Output path is invalid.\n");
        }
        print "Cleaning {$output_path}...\n";
        // Clean ./**/CMakeLists.txt and ./**/CMakeCache.txt ./**/cmake_install.cmake
        $fsearch = "\\( -name 'CMakeLists.txt' -or -name 'CMakeCache.txt' -or -name 'cmake_install.cmake' \\)";
        $fcmd = "find \"{$output_path}\" {$fsearch} -print -exec rm -R {} +";
        if (Baker::$verbosity > -1) {
            print "{$fcmd}\n";
        }
        print shell_exec($fcmd);
        // Clean ./**/CMakeFiles/
        $fsearch = "\\( -name 'CMakeFiles' -and -type d \\) -prune";
        $fcmd = "find \"{$output_path}\" {$fsearch} -print -exec rm -R {} +";
        if (Baker::$verbosity > -1) {
            print "{$fcmd}\n";
        }
        print shell_exec($fcmd);
        // Clean ./CMake/
        if (Baker::$verbosity > -1) {
            print "rm -R \"{$output_path}/CMake\"\n";
        }
        print shell_exec("rm -R \"{$output_path}/CMake\"");
        exit(0);
    }
    
    return new Baker($output_path);
}

/****************************************************************************/
return LoadBake($GLOBALS['argv']);

?>
