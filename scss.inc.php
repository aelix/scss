<?php

class scssc {
	static protected $operatorNames = array(
		'+' => "add",
		'-' => "sub",
		'*' => "mul",
		'/' => "div",
		'%' => "mod",

		'==' => "eq",
		'!=' => "neq",
		'<' => "lt",
		'>' => "gt",

		'<=' => "lte",
		'>=' => "gte",
	);

	static protected $namespaces = array(
		"mixin" => "@",
		"function" => "^",
	);

	static protected $true = array("keyword", "true");
	static protected $false = array("keyword", "false");

	static protected $defaultValue = array("keyword", "");
	static public $selfSelector = array("self");

	protected $importPaths = array("");

	function compile($code, $name=null) {
		$this->indentLevel = -1;
		$this->commentsSeen = array();
		$this->extends = array();
		$this->extendsMap = array();

		$this->parser = new scss_parser($name);
		$tree = $this->parser->parse($code);

		$formatter = new scss_formatter();

		$this->env = null;
		$scope = $this->compileRoot($tree);

		$this->flattenSelectors($scope);

		ob_start();
		$formatter->block($scope);
		return ob_get_clean();
	}

	protected function pushExtends($target, $origin) {
		$i = count($this->extends);
		$this->extends[] = array($target, $origin);

		foreach ($target as $part) {
			if (isset($this->extendsMap[$part])) {
				$this->extendsMap[$part][] = $i;
			} else {
				$this->extendsMap[$part] = array($i);
			}
		}
	}

	protected function makeOutputBlock($selectors = null) {
		$out = new stdclass;
		$out->lines = array();
		$out->children = array();
		$out->selectors = $selectors;

		return $out;
	}

	protected function matchExtendsSingle($single, &$out_origin, &$out_rem) {
		$counts = array();
		foreach ($single as $part) {
			if (!is_string($part)) return false; // hmm

			if (isset($this->extendsMap[$part])) {
				foreach ($this->extendsMap[$part] as $idx) {
					$counts[$idx] =
						isset($counts[$idx]) ? $counts[$idx] + 1 : 1;
				}
			}
		}

		foreach ($counts as $idx => $count) {
			list($target, $origin) = $this->extends[$idx];
			// check count
			if ($count != count($target)) continue;
			// check if target is subset of single
			if (array_diff(array_intersect($single, $target), $target)) continue;

			$out_origin = $origin;
			$out_rem = array_diff($single, $target);

			return true;
		}

		return false;
	}

	protected function combineSelectorSingle($base, $other) {
		$tag = null;
		$out = array();

		foreach (array($base, $other) as $single) {
			foreach ($single as $part) {
				if (preg_match('/^[^.#:]/', $part)) {
					$tag = $part;
				} else {
					$out[] = $part;
				}
			}
		}

		if ($tag) {
			array_unshift($out, $tag);
		}

		return $out;
	}

	protected function matchExtends($selector, &$out, $from = 0, $initial=true) {
		foreach ($selector as $i => $part) {
			if ($i < $from) continue;

			if ($this->matchExtendsSingle($part, $origin, $rem)) {
				$before = array_slice($selector, 0, $i);
				$after = array_slice($selector, $i + 1);

				foreach ($origin as $new) {
					$new[count($new) - 1] =
						$this->combineSelectorSingle(end($new), $rem);

					$k = 0;
					// remove shared parts
					if ($initial) {
						foreach ($before as $k => $val) {
							if (!isset($new[$k]) || $val != $new[$k]) {
								break;
							}
						}
					}

					$result = array_merge(
						$before,
						$k > 0 ? array_slice($new, $k) : $new,
						$after);


					if ($result == $selector) continue;
					$out[] = $result;

					// recursively check for more matches
					$this->matchExtends($result, $out, $i, false);

					// selector sequence merging
					if (!empty($before) && count($new) > 1) {
						$result2 = array_merge(
							array_slice($new, 0, -1),
							$k > 0 ? array_slice($before, $k) : $before,
							array_slice($new, -1),
							$after);

						$out[] = $result2;
					}
				}
			}
		}
	}

	protected function flattenSelectors($block) {
		if ($block->selectors) {
			$selectors = array();
			foreach ($block->selectors as $s) {
				$selectors[] = $s;
				if (!is_array($s)) continue;
				// check extends
				if (!empty($this->extendsMap)) {
					$this->matchExtends($s, $selectors);
				}
			}

			$selectors = array_map(array($this, "compileSelector"), $selectors);
			$block->selectors = $selectors;
		}

		foreach ($block->children as $child) {
			$this->flattenSelectors($child);
		}
	}

	protected function compileRoot($rootBlock) {
		$this->pushEnv($rootBlock);
		$this->topScope = $this->scope = $this->makeOutputBlock();
		$this->compileChildren($rootBlock->children, $this->scope);
		$this->popEnv();
		return $this->topScope;
	}

	protected function compileMedia($media) {
		$this->pushEnv($media);
		$oldScope = $this->scope;
		$this->scope = $this->makeOutputBlock(array(
			$this->compileMediaQuery($this->multiplyMedia($this->env))
		));
		$this->topScope->children[] = $this->scope;
		$this->compileChildren($media->children, $this->scope);

		$this->scope = $oldScope;
		$this->popEnv();
	}

	protected function compileBlock($block) {
		$env = $this->pushEnv($block);

		$env->selectors =
			array_map(array($this, "evalSelector"), $block->selectors);

		$out = $this->makeOutputBlock($this->multiplySelectors($env));
		$this->scope->children[] = $out;
		$this->compileChildren($block->children, $out);

		$this->popEnv();
	}

	protected function compilePropertyName($prop) {
		list(, $parts) = $prop;
		if (is_array($parts)) {
			foreach ($parts as &$part) {
				if (!is_string($part)) {
					$part = $this->compileValue($part);
				}
			}
			$parts = implode($parts);
		}
		return $parts;
	}

	// joins together .classes and #ids
	protected function flattenSelectorSingle($single) {
		$joined = array();
		foreach ($single as $part) {
			if (empty($joined) ||
				!is_string($part) ||
				preg_match('/[.:#]/', $part))
			{
				$joined[] = $part;
				continue;
			}

			if (is_array(end($joined))) {
				$joined[] = $part;
			} else {
				$joined[count($joined) - 1] .= $part;
			}
		}

		return $joined;
	}

	// replaces all the interpolates
	protected function evalSelector($selector) {
		return array_map(array($this, "evalSelectorPart"), $selector);
	}

	protected function evalSelectorPart($piece) {
		foreach ($piece as &$p) {
			if (!is_array($p)) continue;

			switch ($p[0]) {
			case "interpolate":
				$p = $this->compileValue($p);
				break;
			}
		}

		return $this->flattenSelectorSingle($piece);
	}

	// compiles to string
	// self(&) should have been replaced by now
	protected function compileSelector($selector) {
		if (!is_array($selector)) return $selector; // media and the like

		return implode(" ", array_map(
			array($this, "compileSelectorPart"), $selector));
	}

	protected function compileSelectorPart($piece) {
		foreach ($piece as &$p) {
			if (!is_array($p)) continue;

			switch ($p[0]) {
			case "parens":
				$inner = implode(", ", array_map(
					array($this, "compileSelector"),
					$p[1]));
				$p = "($inner)";
				break;
			case "self":
				$p = "&";
				break;
			default:
				$p = $this->compileValue($p);
				break;
			}
		}

		return implode($piece);
	}

	protected function compileChildren($stms, $out) {
		foreach ($stms as $stm) {
			$ret = $this->compileChild($stm, $out);
			if (!is_null($ret)) return $ret;
		}
	}

	protected function compileMediaQuery($query) {
		$parts = array();
		foreach ($query as $q) {
			switch ($q[0]) {
			case "mediaType":
				$parts[] = implode(" ", array_slice($q, 1));
				break;
			case "mediaExp":
				if (isset($q[2])) {
					$parts[] = "($q[1]: " . $this->compileValue($q[2]) . ")";
				} else {
					$parts[] = "($q[1])";
				}
				break;
			}
		}

		$out = "@media";
		if (!empty($parts)) {
			$out = $out . " " . implode(" and ", $parts);
		}
		return $out;
	}

	// returns true if the value was something that could be imported
	protected function compileImport($rawPath, $out) {
		if ($rawPath[0] == "string") {
			$path = $this->compileStringContent($rawPath);
			if ($path = $this->findImport($path)) {
				$this->importFile($path, $out);
				return true;
			}
			return false;
		} if ($rawPath[0] == "list") {
			// handle a list of strings
			if (count($rawPath[2]) == 0) return false;
			foreach ($rawPath[2] as $path) {
				if ($path[0] != "string") return false;
			}

			foreach ($rawPath[2] as $path) {
				$this->compileImport($path, $out);
			}

			return true;
		}

		return false;
	}

	// return a value to halt execution
	protected function compileChild($child, $out) {
		switch ($child[0]) {
		case "import":
			list(,$rawPath) = $child;
			$rawPath = $this->reduce($rawPath);
			if (!$this->compileImport($rawPath, $out)) {
				$out->lines[] = "@import " . $this->compileValue($rawPath) . ";";
			}
			break;
		case "media":
			$this->compileMedia($child[1]);
			break;
		case "block":
			$this->compileBlock($child[1]);
			break;
		case "assign":
			list(,$name, $value) = $child;
			switch ($name[0]) {
			case "var":
				$isDefault = !empty($child[3]);
				if (!$isDefault || $this->get($name[1], true) === true) {
					$this->set($name[1], $this->reduce($value));
				}
				break;
			case "property":
				$out->lines[] =
					$this->compilePropertyName($child[1]) . ":" .
					$this->compileValue($child[2]) . ";";
				break;
			}
			break;
		case "comment":
			$out->lines[] = $child[1];
			break;
		case "mixin":
		case "function":
			list(,$block) = $child;
			$this->set(self::$namespaces[$block->type] . $block->name, $block);
			break;
		case "extend":
			list(, $selectors) = $child;
			foreach ($selectors as $sel) {
				// only use the first one
				$sel = current($this->evalSelector($sel));
				$this->pushExtends($sel, $out->selectors);
			}
			break;
		case "if":
			list(, $if) = $child;
			if ($this->reduce($if->cond) != self::$false) {
				return $this->compileChildren($if->children, $out);
			} else {
				foreach ($if->cases as $case) {
					if ($case->type == "else" ||
						$case->type == "elseif" && ($this->reduce($case->cond) != self::$false))
					{
						return $this->compileChildren($case->children, $out);
					}
				}
			}
			break;
		case "return":
			return $this->reduce($child[1]);
		case "each":
			list(,$each) = $child;
			$list = $this->reduce($this->coerceList($each->list));
			foreach ($list[2] as $item) {
				$this->pushEnv();
				$this->set($each->var, $item);
				// TODO: allow return from here
				$this->compileChildren($each->children, $out);
				$this->popEnv();
			}
			break;
		case "while":
			list(,$while) = $child;
			while ($this->reduce($while->cond) != self::$false) {
				$ret = $this->compileChildren($while->children, $out);
				if ($ret) return $ret;
			}
			break;
		case "for":
			list(,$for) = $child;
			$start = $this->reduce($for->start);
			$start = $start[1];
			$end = $this->reduce($for->end);
			$end = $end[1];
			$d = $start < $end ? 1 : -1;

			while (true) {
				if ((!$for->until && $start - $d == $end) ||
					($for->until && $start == $end))
				{
					break;
				}

				$this->set($for->var, array("number", $start, ""));
				$start += $d;

				$ret = $this->compileChildren($for->children, $out);
				if ($ret) return $ret;
			}

			break;
		case "nestedprop":
			list(,$prop) = $child;
			$prefixed = array();
			$prefix = $this->compilePropertyName($prop->prefix);
			foreach ($prop->children as $child) {
				if ($child[0] == "assign") {
					// replace assign name with merged one
					$child[1] = array(
						"property",
						$prefix . "-" . $this->compilePropertyName($child[1])
					);
				}
				$prefixed[] = $child;
			}
			$this->compileChildren($prefixed, $out);
			break;
		case "include": // including a mixin
			list(,$name, $argValues) = $child;
			$mixin = $this->get(self::$namespaces["mixin"] . $name, false);
			if (!$mixin) break; // throw error?

			// push scope, apply args
			$this->pushEnv();
			if (!is_null($mixin->args)) {
				$this->applyArguments($mixin->args, $argValues);
			}

			foreach ($mixin->children as $child) {
				$this->compileChild($child, $out);
			}

			$this->popEnv();

			break;
		case "debug":
			list(,$value, $pos) = $child;
			$line = $this->parser->getLineNo($pos);
			$value = $this->compileValue($value);
			fwrite(STDERR, "Line $line DEBUG: $value\n");
			break;
		default:
			throw new exception("unknown child type: $child[0]");
		}
	}

	protected function reduce($value, $inExp = false) {
		list($type) = $value;
		switch ($type) {
			case "exp":
				list(, $op, $left, $right, $inParens) = $value;
				$opName = self::$operatorNames[$op];

				// only do division in special cases
				// TODO: add variables type check here
				if ($opName == "div" && !$inParens && !$inExp) {
					return array("keyword", $this->compileValue($left) . "/" . $this->compileValue($right));
				}

				$left = $this->reduce($left, true);
				$right = $this->reduce($right, true);

				$fn = "op_${opName}_${left[0]}_${right[0]}";
				if (is_callable(array($this, $fn)) || ($fn = "op_${opName}") && is_callable(array($this, $fn))) {
					return $this->$fn($left, $right);
				}

				// remember the whitespace around the operator to recreate it?
				return array("list", "", array($left, array("keyword", $op), $right));
			case "var":
				list(, $name) = $value;
				return $this->reduce($this->get($name));
			case "list":
				foreach ($value[2] as &$item) {
					$item = $this->reduce($item);
				}
				return $value;
			case "string":
				foreach ($value[2] as &$item) {
					if (is_array($item)) {
						$item = $this->reduce($item);
					}
				}
				return $value;
			case "interpolate":
				$value[1] = $this->reduce($value[1]);
				return $value;
			case "fncall":
				list(,$name, $argValues) = $value;
				// user defined function?
				$func = $this->get(self::$namespaces["function"] . $name, false);
				if ($func) {
					$this->pushEnv();

					// set the args
					if (isset($func->args)) {
						$this->applyArguments($func->args, $argValues);
					}

					// throw away lines and children
					$tmp = (object)array(
						"lines" => array(),
						"children" => array()
					);
					$ret = $this->compileChildren($func->children, $tmp);
					$this->popEnv();

					return is_null($ret) ? self::$defaultValue : $ret;
				}

				// need to flatten the arguments into a list
				$listArgs = array();
				foreach ((array)$argValues as $arg) {
					if (empty($arg[0])) {
						$listArgs[] = $this->reduce($arg[1]);
					}
				}
				return array("function", $name, array("list", ",", $listArgs));
			default:
				return $value;
		}
	}

	protected function op_add_number_number($left, $right) {
		return array("number", $left[1] + $right[1], "");
	}

	protected function op_mul_number_number($left, $right) {
		return array("number", $left[1] * $right[1], "");
	}

	protected function op_sub_number_number($left, $right) {
		return array("number", $left[1] - $right[1], "");
	}

	protected function op_div_number_number($left, $right) {
		return array("number", $left[1] / $right[1], "");
	}

	protected function op_mod_number_number($left, $right) {
		return array("number", $left[1] % $right[1], "");
	}

	protected function op_eq($left, $right) {
		return $this->toBool($left == $right);
	}

	protected function op_neq($left, $right) {
		return $this->toBool($left != $right);
	}

	protected function op_gte_number_number($left, $right) {
		return $this->toBool($left[1] >= $right[1]);
	}

	protected function op_gt_number_number($left, $right) {
		return $this->toBool($left[1] > $right[1]);
	}

	protected function op_lte_number_number($left, $right) {
		return $this->toBool($left[1] <= $right[1]);
	}

	protected function op_lt_number_number($left, $right) {
		return $this->toBool($left[1] < $right[1]);
	}

	protected function toBool($thing) {
		return $thing ? self::$true : self::$false;
	}

	protected function compileValue($value) {
		$value = $this->reduce($value);

		list($type) = $value;
		switch ($type) {
		case "keyword":
			return $value[1];
		case "color":
			// [1] - red component (either number for a %)
			// [2] - green component
			// [3] - blue component
			// [4] - optional alpha component
			list(, $r, $g, $b) = $value;
			$r = round($r);
			$g = round($g);
			$b = round($b);

			if (count($value) == 5 && $value[4] != 1) { // rgba
				return 'rgba('.$r.','.$g.','.$b.','.$value[4].')';
			}

			$h = sprintf("#%02x%02x%02x", $r, $g, $b);

			// Converting hex color to short notation (e.g. #003399 to #039)
			if ($h[1] === $h[2] && $h[3] === $h[4] && $h[5] === $h[6]) {
				$h = '#' . $h[1] . $h[3] . $h[5];
			}

			return $h;
		case "number":
			return $value[1] . $value[2];
		case "string":
			return $value[1] . $this->compileStringContent($value) . $value[1];
		case "function":
			$args = !empty($value[2]) ? $this->compileValue($value[2]) : "";
			return "$value[1]($args)";
		case "list":
			$value = $this->extractInterpolation($value);
			if ($value[0] != "list") return $this->compileValue($value);

			list(, $delim, $items) = $value;
			foreach ($items as &$item) {
				$item = $this->compileValue($item);
			}
			return implode("$delim ", $items);
		case "interpolated": # node created by extractInterpolation
			list(, $interpolate, $left, $right) = $value;
			list(,, $white_left, $white_right) = $interpolate;

			$left = count($left[2]) > 0 ?
				$this->compileValue($left).$white_left : "";

			$right = count($right[2]) > 0 ?
				$white_right.$this->compileValue($right) : "";

			return $left.$this->compileValue($interpolate).$right;

		case "interpolate": # raw parse node
			list(, $exp) = $value;

			// strip quotes if it's a string
			$reduced = $this->reduce($exp);
			if ($reduced[0] == "string") {
				$reduced = array("keyword",
					$this->compileStringContent($reduced));
			}

			return $this->compileValue($reduced);
		default:
			throw new exception("unknown value type: $type");
		}
	}

	protected function compileStringContent($string) {
		$parts = array();
		foreach ($string[2] as $part) {
			if (is_array($part)) {
				$parts[] = $this->compileValue($part);
			} else {
				$parts[] = $part;
			}
		}

		return implode($parts);
	}

	// doesn't need to be recursive, compileValue will handle that
	protected function extractInterpolation($list) {
		$items = $list[2];
		foreach ($items as $i => $item) {
			if ($item[0] == "interpolate") {
				$before = array("list", $list[1], array_slice($items, 0, $i));
				$after = array("list", $list[1], array_slice($items, $i + 1));
				return array("interpolated", $item, $before, $after);
			}
		}
		return $list;
	}

	// find the final set of selectors
	protected function multiplySelectors($env, $childSelectors = null) {
		if (is_null($env)) {
			return $childSelectors;
		}

		// skip env, has no selectors
		if (empty($env->selectors)) {
			return $this->multiplySelectors($env->parent, $childSelectors);
		}

		if (is_null($childSelectors)) {
			$selectors = $env->selectors;
		} else {
			$selectors = array();
			foreach ($env->selectors as $parent) {
				foreach ($childSelectors as $child) {
					$selectors[] = $this->joinSelectors($parent, $child);
				}
			}
		}

		return $this->multiplySelectors($env->parent, $selectors);
	}

	// looks for & to replace, or append parent before child
	protected function joinSelectors($parent, $child) {
		$setSelf = false;
		$out = array();
		foreach ($child as $part) {
			$newPart = array();
			foreach ($part as $p) {
				if ($p == self::$selfSelector) {
					$setSelf = true;
					foreach ($parent as $i => $parentPart) {
						if ($i > 0) {
							$out[] = $newPart;
							$newPart = array();
						}

						foreach ($parentPart as $pp) {
							$newPart[] = $pp;
						}
					}
				} else {
					$newPart[] = $p;
				}
			}

			$out[] = $newPart;
		}

		return $setSelf ? $out : array_merge($parent, $child);
	}

	protected function multiplyMedia($env, $childMedia = null) {
		if (is_null($env)) return $childMedia;

		if (empty($env->block->type) || $env->block->type != "media") {
			return $this->multiplyMedia($env->parent, $childMedia);
		}

		$query = $env->block->query;
		if ($childMedia == null) {
			$childMedia = $query;
		} else {
			$childMedia = array_merge($query, $childMedia);
		}

		return $this->multiplyMedia($env->parent, $childMedia);
	}

	// convert something to list
	protected function coerceList($item, $delim = ",") {
		if (!is_null($item) && $item[0] == "list") {
			return $item;
		}

		return array("list", $delim, is_null($item) ? array(): array($item));
	}

	protected function applyArguments($argDef, $argValues) {
		$argValues = (array)$argValues;

		$keywordArgs = array();
		$remaining = array();

		// assign the keyword args
		foreach ($argValues as $arg) {
			if (!empty($arg[0])) {
				$keywordArgs[$arg[0][1]] = $arg[1];
			} else {
				$remaining[] = $arg[1];
			}
		}

		foreach ($argDef as $i => $arg) {
			list($name, $default) = $arg;

			if (isset($remaining[$i])) {
				$val = $remaining[$i];
			} elseif (isset($keywordArgs[$name])) {
				$val = $keywordArgs[$name];
			} elseif (!empty($default)) {
				$val = $default;
			} else {
				$val = self::$defaultValue;
			}

			$this->set($name, $this->reduce($val));
		}
	}

	// we have environments in addition to blocks for handling mixins, where
	// blocks are reused in different contexts
	protected function pushEnv($block=null) {
		$env = new stdclass;
		$env->parent = $this->env;
		$env->store = array();
		$env->block = $block;

		$this->env = $env;
		return $env;
	}

	protected function normalizeName($name) {
		return str_replace("_", "-", $name);
	}

	protected function set($name, $value) {
		$this->env->store[$this->normalizeName($name)] = $value;
	}

	protected function get($name, $defaultValue = null, $env = null) {
		$name = $this->normalizeName($name);

		if (is_null($env)) $env = $this->env;
		if (is_null($defaultValue)) $defaultValue = self::$defaultValue;

		if (isset($env->store[$name])) {
			return $env->store[$name];
		} elseif (!is_null($env->parent)) {
			return $this->get($name, $defaultValue, $env->parent);
		}

		return $defaultValue; // found nothing
	}

	protected function popEnv() {
		$env = $this->env;
		$this->env = $this->env->parent;
		return $env;
	}

	public function addImportPath($path) {
		$this->importPaths[] = $path;
	}

	protected function importFile($path, $out) {
		$code = file_get_contents($path);
		$parser = new scss_parser($path);
		$tree = $parser->parse($code);

		$pi = pathinfo($path);
		array_unshift($this->importPaths, $pi['dirname']);
		$this->compileChildren($tree->children, $out);
		array_shift($this->importPaths);
	}

	// results the file path for an import url if it exists
	protected function findImport($url) {
		if (preg_match('/\.css|^http:\/\/$/', $url)) return null;

		foreach ((array)$this->importPaths as $dir) {
			$full = $dir .
				(!empty($dir) && substr($dir, -1) != '/' ? '/' : '') .
				$url;

			if ($this->fileExists($file = $full.'.scss') ||
				$this->fileExists($file = $full))
			{
				return $file;
			}
		}

		return null;
	}

	protected function fileExists($name) {
		return is_file($name);
	}
}


class scss_parser {
	static protected $precedence = array(
		'==' => 0,
		'!=' => 0,
		'<=' => 0,
		'>=' => 0,
		'=' => 0,
		'<' => 0,
		'>' => 0,

		'+' => 1,
		'-' => 1,
		'*' => 2,
		'/' => 2,
		'%' => 2,
	);

	static protected $operators = array("+", "-", "*", "/", "%",
		"==", "!=", "<=", ">=", "<", ">");

	static protected $operatorStr;
	static protected $whitePattern;

	static protected $commentSingle = "//";
	static protected $commentMultiLeft = "/*";
	static protected $commentMultiRight = "*/";

	function __construct($sourceName = null) {
		$this->sourceName = $sourceName;

		if (empty(self::$operatorStr)) {
			self::$operatorStr = $this->makeOperatorStr(self::$operators);

			$commentSingle = $this->preg_quote(self::$commentSingle);
			$commentMultiLeft = $this->preg_quote(self::$commentMultiLeft);
			$commentMultiRight = $this->preg_quote(self::$commentMultiRight);
			self::$whitePattern = '/'.$commentSingle.'[^\n]*\s*|('.$commentMultiLeft.'.*?'.$commentMultiRight.')\s*|\s+/Ais';
		}
	}

	static protected function makeOperatorStr($operators) {
		return '('.implode('|', array_map(array('scss_parser','preg_quote'),
			$operators)).')';
	}

	function parse($buffer) {
		$this->count = 0;
		$this->env = null;
		$this->inParens = false;
		$this->pushBlock(null); // root block
		$this->eatWhiteDefault = true;

		$this->buffer = $this->removeComments($buffer);

		$this->whitespace();
		while (false !== $this->parseChunk());

		if ($this->count != strlen($this->buffer))
			$this->throwParseError();

		if (!empty($this->env->parent)) {
			$this->throwParseError("unclosed block");
		}

		$this->env->isRoot = true;
		return $this->env;
	}

	protected function parseChunk() {
		$s = $this->seek();

		// the directives
		if (isset($this->buffer[$this->count]) && $this->buffer[$this->count] == "@") {
			if ($this->literal("@media") && $this->mediaQuery($mediaQuery) && $this->literal("{")) {
				$media = $this->pushSpecialBlock("media");
				$media->query = $mediaQuery;
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@mixin") &&
				$this->keyword($mixinName) &&
				($this->argumentDef($args) || true) &&
				$this->literal("{"))
			{
				$mixin = $this->pushSpecialBlock("mixin");
				$mixin->name = $mixinName;
				$mixin->args = $args;
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@include") &&
				$this->keyword($mixinName) &&
				($this->literal("(") &&
					($this->argValues($argValues) || true) &&
					$this->literal(")") || true) &&
				$this->end())
		{
				$this->append(array("include",
					$mixinName,
					isset($argValues) ? $argValues : null));
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@import") &&
				$this->valueList($importPath) &&
				$this->end())
			{
				$this->append(array("import", $importPath));
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@extend") &&
				$this->selectors($selector) &&
				$this->end())
			{
				$this->append(array("extend", $selector));
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@function") &&
				$this->keyword($fn_name) &&
				$this->argumentDef($args) &&
				$this->literal("{"))
			{
				$func = $this->pushSpecialBlock("function");
				$func->name = $fn_name;
				$func->args = $args;
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@return") && $this->valueList($retVal) && $this->end()) {
				$this->append(array("return", $retVal));
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@each") &&
				$this->variable($varName) &&
				$this->literal("in") &&
				$this->valueList($list) &&
				$this->literal("{"))
			{
				$each = $this->pushSpecialBlock("each");
				$each->var = $varName[1];
				$each->list = $list;
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@while") &&
				$this->expression($cond) &&
				$this->literal("{"))
			{
				$while = $this->pushSpecialBlock("while");
				$while->cond = $cond;
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@for") &&
				$this->variable($varName) &&
				$this->literal("from") &&
				$this->expression($start) &&
				($this->literal("through") ||
					($forUntil = true && $this->literal("to"))) &&
				$this->expression($end) &&
				$this->literal("{"))
			{
				$for = $this->pushSpecialBlock("for");
				$for->var = $varName[1];
				$for->start = $start;
				$for->end = $end;
				$for->until = isset($forUntil);
				return true;
			} else {
				$this->seek($s);
			}

			if ($this->literal("@if") && $this->valueList($cond) && $this->literal("{")) {
				$if = $this->pushSpecialBlock("if");
				$if->cond = $cond;
				$if->cases = array();
				return true;
			} else {
				$this->seek($s);
			}

			if (($this->literal("@debug") || $this->literal("@warn")) &&
				$this->valueList($value) &&
				$this->end()) {
				$this->append(array("debug", $value, $s));
				return true;
			} else {
				$this->seek($s);
			}

			$last = $this->last();
			if (!is_null($last) && $last[0] == "if") {
				list(, $if) = $last;
				if ($this->literal("@else")) {
					if ($this->literal("{")) {
						$else = $this->pushSpecialBlock("else");
					} elseif ($this->literal("if") && $this->valueList($cond) && $this->literal("{")) {
						$else = $this->pushSpecialBlock("elseif");
						$else->cond = $cond;
					}

					if (isset($else)) {
						$else->dontAppend = true;
						$if->cases[] = $else;
						return true;
					}
				}

				$this->seek($s);
			}
		}

		// nested property assign (or normal assign)
		if ($this->propertyName($name) && $this->literal(": ")) {
			$foundSomething = false;
			// look for value
			if ($this->valueList($value)) {
				$foundSomething = true;
				$this->append(array("assign", $name, $value));
			}

			// look for nested property block
			if ($this->literal("{")) {
				$foundSomething = true;
				$propBlock = $this->pushSpecialBlock("nestedprop");
				$propBlock->prefix = $name;
			} elseif ($foundSomething) {
				$foundSomething = $this->end();
			}

			if ($foundSomething) {
				return true;
			}
			return true;
		} else {
			$this->seek($s);
		}

		// opening css block
		if ($this->selectors($selectors) && $this->literal("{")) {
			$this->pushBlock($selectors);
			return true;
		} else {
			$this->seek($s);
		}

		// all other types of assign (including variable)
		if (($this->variable($name) || $this->propertyName($name)) &&
			$this->literal(":") &&
			$this->valueList($value) &&
			$this->end())
		{
			$assign = array("assign", $name);
			if ($name[0] == "var") {
				$defaultVar = false;
				// check for !default
				if ($value[0] == "list") {
					$def = end($value[2]);
					if ($def[0] == "keyword" && $def[1] == "!default") {
						array_pop($value[2]);
						$defaultVar = true;
					}
				}
				$assign[] = $value;
				$assign[] = $defaultVar;
			} else {
				$assign[] = $value;
			}

			$this->append($assign);
			return true;
		} else {
			$this->seek($s);
		}

		// opening a property block
		if ($this->keyword($prefix) && $this->literal(":") && $this->literal("{")) {
			$this->pushSpecialBlock("property");
			return true;
		} else {
			$this->seek($s);
		}

		// closing a block
		if ($this->literal("}")) {
			$block = $this->popBlock();
			if (empty($block->dontAppend)) {
				$type = isset($block->type) ? $block->type : "block";
				$this->append(array($type, $block));
			}
			return true;
		}

		return false;
	}

	protected function literal($what, $eatWhitespace = true) {
		// this is here mainly prevent notice from { } string accessor
		if ($this->count >= strlen($this->buffer)) return false;

		// shortcut on single letter
		if (!$eatWhitespace && strlen($what) == 1) {
			if ($this->buffer{$this->count} == $what) {
				$this->count++;
				return true;
			}
			else return false;
		}

		return $this->match($this->preg_quote($what), $m, $eatWhitespace);
	}

	// tree builders

	protected function pushBlock($selectors) {
		$b = new stdclass;
		$b->parent = $this->env; // not sure if we need this yet

		$b->selectors = $selectors;
		$b->children = array();

		$this->env = $b;
		return $b;
	}

	protected function pushSpecialBlock($type) {
		$block = $this->pushBlock(null);
		$block->type = $type;
		return $block;
	}

	protected function popBlock() {
		if (empty($this->env->parent)) {
			$this->throwParseError("unexpected }");
		}

		$old = $this->env;
		$this->env = $this->env->parent;
		unset($old->parent);
		return $old;
	}

	protected function append($statement) {
		$this->env->children[] = $statement;
	}

	// last child that was appended
	protected function last() {
		$i = count($this->env->children) - 1;
		if (isset($this->env->children[$i]))
			return $this->env->children[$i];
	}

	// high level parsers (they return parts of ast)

	protected function mediaQueryList(&$out) {
		return $this->genericList($out, "mediaQuery");
	}

	protected function mediaQuery(&$out) {
		$s = $this->seek();

		$expressions = null;
		$parts = array();

		if (($this->literal("only") && ($only = true) || $this->literal("not") && ($not = true) || true) && $this->keyword($mediaType)) {
			$prop = array("mediaType");
			if (isset($only)) $prop[] = "only";
			if (isset($not)) $prop[] = "not";
			$prop[] = $mediaType;
			$parts[] = $prop;
		} else {
			$this->seek($s);
		}


		if (!empty($mediaType) && !$this->literal("and")) {
			// ~
		} else {
			$this->genericList($expressions, "mediaExpression", "and", false);
			if (is_array($expressions)) $parts = array_merge($parts, $expressions[2]);
		}

		$out = $parts;
		return true;
	}

	protected function mediaExpression(&$out) {
		$s = $this->seek();
		$value = null;
		if ($this->literal("(") &&
			$this->keyword($feature) &&
			($this->literal(":") && $this->expression($value) || true) &&
			$this->literal(")"))
		{
			$out = array("mediaExp", $feature);
			if ($value) $out[] = $value;
			return true;
		}

		$this->seek($s);
		return false;
	}

	protected function argValues(&$out) {
		if ($this->genericList($list, "argValue", ",", false)) {
			$out = $list[2];
			return true;
		}
		return false;
	}

	protected function argValue(&$out) {
		$s = $this->seek();

		$keyword = null;
		if (!$this->variable($keyword) || !$this->literal(":")) {
			$this->seek($s);
			$keyword = null;
		}

		if ($this->genericList($value, "expression")) {
			$out = array($keyword, $value);
			return true;
		}

		return false;
	}


	protected function valueList(&$out) {
		return $this->genericList($out, "commaList");
	}

	protected function commaList(&$out) {
		return $this->genericList($out, "expression", ",");
	}

	protected function genericList(&$out, $parseItem, $delim="", $flatten=true) {
		$s = $this->seek();
		$items = array();
		while ($this->$parseItem($value)) {
			$items[] = $value;
			if ($delim) {
				if (!$this->literal($delim)) break;
			}
		}

		if (count($items) == 0) {
			$this->seek($s);
			return false;
		}

		if ($flatten && count($items) == 1) {
			$out = $items[0];
		} else {
			$out = array("list", $delim, $items);
		}

		return true;
	}

	protected function expression(&$out) {
		if ($this->value($lhs)) {
			$out = $this->expHelper($lhs, 0);
			return true;
		}
		return false;
	}

	protected function expHelper($lhs, $minP) {
		$opstr = self::$operatorStr;

		$ss = $this->seek();
		while ($this->match($opstr, $m) && self::$precedence[$m[1]] >= $minP) {
			$op = $m[1];

			if (!$this->value($rhs)) break;

			// peek and see if rhs belongs to next operator
			if ($this->peek($opstr, $next) && self::$precedence[$next[1]] > self::$precedence[$op]) {
				$rhs = $this->expHelper($rhs, self::$precedence[$next[1]]);
			}

			$lhs = array("exp", $op, $lhs, $rhs, $this->inParens);
			$ss = $this->seek();
		}

		$this->seek($ss);
		return $lhs;
	}

	protected function value(&$out) {
		$s = $this->seek();

		// parens
		$inParens = $this->inParens;
		if ($this->literal("(") &&
			($this->inParens = true) && $this->expression($exp) &&
			$this->literal(")"))
		{
			$out = $exp;
			$this->inParens = $inParens;
			return true;
		} else {
			$this->inParens = $inParens;
			$this->seek($s);
		}

		if ($this->interpolation($out)) return true;
		if ($this->variable($out)) return true;
		if ($this->color($out)) return true;
		if ($this->unit($out)) return true;
		if ($this->string($out)) return true;
		if ($this->func($out)) return true;

		// convert keyword to be more borad and include numbers/symbols
		if ($this->keyword($keyword)) {
			$out = array("keyword", $keyword);
			return true;
		}


		return false;
	}

	protected function func(&$func) {
		$s = $this->seek();

		if ($this->keyword($name, false) &&
			$this->literal("(") &&
			($this->argValues($args) || true) &&
			$this->literal(")"))
		{
			$func = array("fncall", $name, $args);
			return true;
		}

		$this->seek($s);
		return false;
	}

	protected function argumentDef(&$out) {
		$s = $this->seek();
		$this->literal("(");

		$args = array();
		while ($this->variable($var)) {
			$arg = array($var[1], null);

			$ss = $this->seek();
			if ($this->literal(":") && $this->expression($defaultVal)) {
				$arg[1] = $defaultVal;
			} else {
				$this->seek($ss);
			}

			$args[] = $arg;
			if (!$this->literal(",")) break;
		}

		if (!$this->literal(")")) {
			$this->seek($s);
			return false;
		}

		$out = $args;
		return true;
	}

	protected function color(&$out) {
		$color = array('color');

		if ($this->match('(#([0-9a-f]{6})|#([0-9a-f]{3}))', $m)) {
			if (isset($m[3])) {
				$num = $m[3];
				$width = 16;
			} else {
				$num = $m[2];
				$width = 256;
			}

			$num = hexdec($num);
			foreach (array(3,2,1) as $i) {
				$t = $num % $width;
				$num /= $width;

				$color[$i] = $t * (256/$width) + $t * floor(16/$width);
			}

			$out = $color;
			return true;
		}

		return false;
	}

	protected function unit(&$unit) {
		if ($this->match('(-?[0-9]*(\.)?[0-9]+)([%a-zA-Z]+)?', $m)) {
			$unit = array("number", $m[1], empty($m[3]) ? "" : $m[3]);
			return true;
		}
		return false;
	}

	protected function string(&$out) {
		$s = $this->seek();
		if ($this->literal('"', false)) {
			$delim = '"';
		} elseif ($this->literal("'", false)) {
			$delim = "'";
		} else {
			return false;
		}

		$content = array();

		// look for either ending delim or string interpolation
		$patt = '([^\n]*?)('.
			$this->preg_quote("#{").'|'. $this->preg_quote($delim).')';

		while ($this->match($patt, $m)) {
			$content[] = $m[1];
			if ($m[2] == "#{") {
				$ss = $this->seek();
				if ($this->valueList($value) && $this->literal("}", false)) {
					$content[] = array("interpolate", $value);
				} else {
					$this->seek($ss);
					$content[] = "#{"; // ignore it
				}
			} else {
				break; // delim
			}
		}

		$out = array("string", $delim, $content);
		return true;
	}

	// where should this be parsed?
	protected function interpolation(&$out) {
		$s = $this->seek();
		if ($this->literal("#{") && $this->valueList($value) && $this->literal("}", false)) {

			// TODO: don't error if out of bounds
			$left = preg_match('/\s/', $this->buffer[$s - 1]) ? " " : "";
			$right = preg_match('/\s/', $this->buffer[$this->count]) ? " ": "";

			// get rid of the whitespace we didn't get before
			$this->match("", $m);

			$out = array("interpolate", $value, $left, $right);
			return true;
		}

		$this->seek($s);
		return false;
	}

	// low level parsers

	// returns an array of parts or a string
	protected function propertyName(&$out) {
		$s = $this->seek();

		$parts = array();
		$hasInterpolation = false;
		while (true) {
			if ($this->interpolation($inter)) {
				$hasInterpolation = true;
				$parts[] = $inter;
			} elseif ($this->keyword($text)) {
				$parts[] = $text;
			} else {
				break;
			}
		}

		if (count($parts) == 0) return false;
		if ($hasInterpolation) {
			$out = $parts;
		} else {
			$out = implode($parts);
		}
		$out = array("property", $out);
		return true;
	}

	protected function selectors(&$out) {
		$s = $this->seek();
		$selectors = array();
		while ($this->selector($sel)) {
			$selectors[] = $sel;
			if (!$this->literal(",")) break;
		}

		if (count($selectors) == 0) {
			$this->seek($s);
			return false;
		}

		$out = $selectors;
		return true;
	}

	protected function selector(&$out) {
		$selector = array();
		while ($this->selectorSingle($part)) {
			$selector[] = $part;
			$this->whitespace();

			if ($this->match('[>+~]', $m)) {
				$selector[] = array($m[0]);
			}
		}

		if (count($selector) == 0) {
			return false;
		}

		$out = $selector;
		return true;
	}

	// div[yes=no]#something.hello.world:nth-child(-2n+1)
	protected function selectorSingle(&$out) {
		$oldWhite = $this->eatWhiteDefault;
		$this->eatWhiteDefault = false;

		$parts = array();

		if ($this->literal("*", false)) {
			$parts[] = "*";
		}

		while (true) {
			// see if we can stop early
			if ($this->match("\s*[{,]", $m)) {
				$this->count--;
				break;
			}

			$s = $this->seek();
			// self
			if ($this->literal("&", false)) {
				$parts[] = scssc::$selfSelector;
				continue;
			}

			if ($this->literal(".", false)) {
				$parts[] = ".";
				continue;
			}

			if ($this->keyword($name)) {
				$parts[] = $name;
				continue;
			}

			if ($this->interpolation($inter)) {
				$parts[] = $inter;
				continue;
			}

			if ($this->literal("#", false)) {
				$parts[] = "#";
				continue;
			}

			// a pseudo selector
			if ($this->match("::?", $m) && $this->keyword($name)) {
				$parts[] = $m[0] . $name;

				$ss = $this->seek();
				if ($this->literal("(")) {
					$this->selectors($inner);

					if ($this->literal(")")) {
						$parts[] = array("parens",
							is_null($inner) ? array() : $inner);
					} else {
						$this->seek($ss);
					}
				}

				continue;
			} else {
				$this->seek($s);
			}


			// attribute selector
			if ($this->literal("[", false)) {
				$attrParts = array("[");
				// keyword, string, operator
				while (true) {
					if ($this->literal("]", false)) {
						$this->count--;
						break; // get out early
					}

					if ($this->match('\s+', $m)) {
						$attrParts[] = " ";
						continue;
					}
					if ($this->string($str)) {
						$attrParts[] = $str;
						continue;
					}

					if ($this->keyword($word)) {
						$attrParts[] = $word;
						continue;
					}

					// operator, handles attr namespace too
					if ($this->match('[|-~\$\*\^=]+', $m)) {
						$attrParts[] = $m[0];
						continue;
					}

					break;
				}

				if ($this->literal("]", false)) {
					$attrParts[] = "]";
					foreach ($attrParts as $part) {
						$parts[] = $part;
					}
					continue;
				}
				$this->seek($s);
				// should just break here?
			}

			break;
		}

		$this->eatWhiteDefault = $oldWhite;

		if (count($parts) == 0) return false;

		$out = $parts;
		return true;
	}

	protected function variable(&$out) {
		$s = $this->seek();
		if ($this->literal("$", false) && $this->keyword($name)) {
			$out = array("var", $name);
			return true;
		}
		$this->seek($s);
		return false;
	}

	protected function keyword(&$word, $eatWhitespace = null) {
		if ($this->match('([\w_\-\*!"][\w\-_"]*)', $m, $eatWhitespace)) {
			$word = $m[1];
			return true;
		}
		return false;
	}

	// consume an end of statement delimiter
	protected function end() {
		if ($this->literal(';')) {
			return true;
		} elseif ($this->count == strlen($this->buffer) || $this->buffer{$this->count} == '}') {
			// if there is end of file or a closing block next then we don't need a ;
			return true;
		}
		return false;
	}

	// advance counter to next occurrence of $what
	// $until - don't include $what in advance
	// $allowNewline, if string, will be used as valid char set
	protected function to($what, &$out, $until = false, $allowNewline = false) {
		if (is_string($allowNewline)) {
			$validChars = $allowNewline;
		} else {
			$validChars = $allowNewline ? "." : "[^\n]";
		}
		if (!$this->match('('.$validChars.'*?)'.$this->preg_quote($what), $m, !$until)) return false;
		if ($until) $this->count -= strlen($what); // give back $what
		$out = $m[1];
		return true;
	}

	protected function throwParseError($msg = "parse error", $count = null) {
		$count = is_null($count) ? $this->count : $count;

		$line = $this->getLineNo($count);

		if (!empty($this->sourceName)) {
			$loc = "$this->sourceName on line $line";
		} else {
			$loc = "line: $line";
		}

		if ($this->peek("(.*?)(\n|$)", $m, $count)) {
			throw new exception("$msg: failed at `$m[1]` $loc");
		} else {
			throw new exception("$msg: $loc");
		}
	}

	public function getLineNo($pos) {
		return 1 + substr_count(substr($this->buffer, 0, $pos), "\n");
	}

	// try to match something on head of buffer
	protected function match($regex, &$out, $eatWhitespace = null) {
		if (is_null($eatWhitespace)) $eatWhitespace = $this->eatWhiteDefault;

		$r = '/'.$regex.'/Ais';
		if (preg_match($r, $this->buffer, $out, null, $this->count)) {
			$this->count += strlen($out[0]);
			if ($eatWhitespace) $this->whitespace();
			return true;
		}
		return false;
	}

	// match some whitespace
	protected function whitespace() {
		$gotWhite = false;
		while (preg_match(self::$whitePattern, $this->buffer, $m, null, $this->count)) {
			if (isset($m[1]) && empty($this->commentsSeen[$this->count])) {
				$this->append(array("comment", $m[1]));
				$this->commentsSeen[$this->count] = true;
			}
			$this->count += strlen($m[0]);
			$gotWhite = true;
		}
		return $gotWhite;
	}

	protected function peek($regex, &$out, $from=null) {
		if (is_null($from)) $from = $this->count;

		$r = '/'.$regex.'/Ais';
		$result = preg_match($r, $this->buffer, $out, null, $from);

		return $result;
	}

	protected function seek($where = null) {
		if ($where === null) return $this->count;
		else $this->count = $where;
		return true;
	}

	protected function removeComments($buffer) {
		return $buffer; // TODO: this
	}

	static function preg_quote($what) {
		return preg_quote($what, '/');
	}
}

class scss_formatter {
	public $indentChar = "  ";

	public $break = "\n";
	public $open = " {";
	public $close = "}";
	public $tagSeparator = ", ";

	public $disableSingle = false;
	public $openSingle = " { ";
	public $closeSingle = " }";

	public function __construct() {
		$this->indentLevel = 0;
	}

	public function block($block) {
		if (empty($block->lines) && empty($block->children)) return;

		$isSingle = false;
		if (!empty($block->selectors)) {
			$preIndent = str_repeat($this->indentChar, $this->indentLevel);
			echo $preIndent .
				implode($this->tagSeparator, $block->selectors);

			// check for single mode
			if (count($block->lines) == 1 && empty($block->children)) {
				echo $this->openSingle;
				$isSingle = true;
			} else {
				echo $this->open . $this->break;
			}

			$this->indentLevel++;
		}

		$indent = str_repeat($this->indentChar, $this->indentLevel);

		if ($isSingle) {
			echo $block->lines[0];
		} elseif (count($block->lines) > 0) {
			echo $indent . implode("\n$indent", $block->lines) . $this->break;
		}

		foreach ($block->children as $child) {
			$this->block($child);
		}

		if (!empty($block->selectors)) {
			$this->indentLevel--;
			if ($isSingle) {
				echo $this->closeSingle;
			} else {
				echo $preIndent . $this->close;
			}

			echo $this->break;
		}
	}
}

