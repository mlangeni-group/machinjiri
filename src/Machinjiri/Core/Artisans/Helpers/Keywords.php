<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

class Keywords 
{
  public static function internal (): array 
  {
    return [
      // Language constructs
      '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 
      'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 
      'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 
      'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 
      'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 
      'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 
      'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 
      'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 
      'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 
      'unset', 'use', 'var', 'while', 'xor', 'yield', 'yield from',
      
      // Compile-time constants (pseudo-keywords)
      '__CLASS__', '__DIR__', '__FILE__', '__FUNCTION__', '__LINE__', 
      '__METHOD__', '__NAMESPACE__', '__TRAIT__',
      
      // Type declarations and primitives
      'bool', 'int', 'float', 'string', 'iterable', 'object', 'mixed', 'void', 
      'never', 'null', 'false', 'true', 'self', 'parent',
      
      // Magic methods
      '__construct', '__destruct', '__call', '__callStatic', '__get', '__set', 
      '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize', 
      '__toString', '__invoke', '__set_state', '__clone', '__debugInfo',
      
      // Special global constants (not strictly keywords but reserved)
      'null', 'true', 'false',
      
      // Predefined variables (reserved scope)
      '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', 
      '$_SESSION', '$_REQUEST', '$_ENV', '$this',
      
      // Future reserved keywords (cannot be used in some contexts)
      'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 
      'iterable', 'object', 'mixed', 'never', 'enum', 'readonly',
      
      // Soft reserved keywords (context-dependent)
      'from', 'where', 'join', 'into', 'on', 'asc', 'desc', 'limit', 'offset', 
      'set', 'values',
    ];
  }
}

