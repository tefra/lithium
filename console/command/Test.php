<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\console\command;

use lithium\core\Libraries;
use lithium\test\Dispatcher;

/**
 * Runs a given set of tests and outputs the results.
 *
 * @see lithium\test
 */
class Test extends \lithium\console\Command {

	/**
	 * List of filters to apply before/during/after test run, separated by commas.
	 *
	 * For example:
	 * {{{
	 * lithium test lithium/tests/cases/core/ObjectTest.php --filters=Coverage
	 * lithium test lithium/tests/cases/core/ObjectTest.php --filters=Coverage,Profiler
	 * }}}
	 *
	 * @var string Name of a filter or a comma separated list of filter names. Builtin filters:
	 *      - `Affected`:   Adds tests to the run affected by the classes covered by current tests.
	 *      - `Complexity`: Calculates the cyclomatic complexity of class methods, and shows
	 *                      worst-offenders and statistics.
	 *      - `Coverage`:   Runs code coverage analysis for the executed tests.
	 *      - `Profiler`:   Tracks timing and memory usage information for each test method.
	 */
	public $filters;

	/**
	 * Format to use for rendering results. Any other format than `txt` will
	 * cause the command to enter quiet mode, surpressing headers and any other
	 * decoration.
	 *
	 * @var string Either `txt` or `json`.
	 */
	public $format = 'txt';

	/**
	 * Enable verbose output especially for the `txt` format.
	 *
	 * @var boolean
	 */
	public $verbose = false;

	/**
	 * An array of closures, mapped by type, which are set up to handle different test output
	 * formats.
	 *
	 * @var array
	 */
	protected $_handlers = array();

	/**
	 * Initializes the output handlers.
	 *
	 * @see lithium\console\command\Test::$_handlers
	 * @return void
	 */
	protected function _init() {
		parent::_init();
		$command = $this;

		$this->_handlers += array(
			'txt' => function($runner, $path) use ($command) {
				$command->header('Test');
				$command->out(null, 1);

				$colorize = function($result) {
					switch (trim($result)) {
						case '.':
							return $result;
						case 'pass':
							return "{:green}{$result}{:end}";
						case 'F':
						case 'fail':
							return "{:red}{$result}{:end}";
						case 'E':
						case 'exception':
							return "{:purple}{$result}{:end}";
						case 'S':
						case 'skip':
							return "{:cyan}{$result}{:end}";
						default:
							return "{:yellow}{$result}{:end}";
					}
				};

				if ($command->verbose) {
					$reporter = function($result) use ($command, $colorize) {
						$command->out(sprintf(
							'[%s] on line %4s in %s::%s()',
							$colorize(sprintf('%9s', $result['result'])),
							isset($result['line']) ? $result['line'] : '??',
							isset($result['class']) ? $result['class'] : '??',
							isset($result['method']) ? $result['method'] : '??'
						));
					};
				} else {
					$i = 0;
					$columns = 60;

					$reporter = function($result) use ($command, &$i, $columns, $colorize) {
						$shorten = array('fail', 'skip', 'exception');

						if ($result['result'] == 'pass') {
							$symbol = '.';
						} elseif (in_array($result['result'], $shorten)) {
							$symbol = strtoupper($result['result'][0]);
						} else {
							$symbol = '?';
						}
						$command->out($colorize($symbol), false);

						$i++;
						if ($i % $columns === 0) {
							$command->out();
						}
					};
				}

				$report = $runner(compact('reporter'));

				$command->out(null, 2);
				$command->out($report->render('stats', $report->stats()));

				foreach ($report->filters() as $filter => $options) {
					$data = $report->results['filters'][$filter];
					$command->out($report->render($options['name'], compact('data')));
				}

				$command->nl();
				return $report;
			},
			'json' => function($runner, $path) use ($command) {
				$report = $runner();

				if ($results = $report->filters()) {
					$filters = array();

					foreach ($results as $filter => $options) {
						$filters[$options['name']] = $report->results['filters'][$filter];
					}
				}
				$command->out($report->render('stats', $report->stats() + compact('filters')));
				return $report;
			}
		);
	}

	/**
	 * Runs tests given a path to a directory or file containing tests. The path to the
	 * test(s) may be absolute or relative to the current working directory.
	 *
	 * {{{
	 * li3 test lithium/tests/cases/core/ObjectTest.php
	 * li3 test lithium/tests/cases/core
	 * }}}
	 *
	 * If you are in the working directory of an application or plugin and wish to run all tests,
	 * simply execute the following:
	 *
	 * {{{
	 * li3 test tests/cases
	 * }}}
	 *
	 * If you are in the working directory of an application and wish to run a plugin, execute one
	 * of the following:
	 *
	 * {{{
	 * li3 test libraries/<plugin>/tests/cases
	 * li3 test <plugin>/tests/cases
	 * }}}
	 *
	 * @param string $path Absolute or relative path to tests.
	 * @return boolean Will exit with status `1` if one or more tests failed otherwise with `0`.
	 */
	public function run($path = null) {
		if (!$path = $this->_path($path)) {
			return false;
		}
		$handlers = $this->_handlers;

		if (!isset($handlers[$this->format]) || !is_callable($handlers[$this->format])) {
			$this->error(sprintf('No handler for format `%s`... ', $this->format));
			return false;
		}
		$filters = $this->filters ? array_map('trim', explode(',', $this->filters)) : array();
		$params = compact('filters') + array('format' => $this->format);
		$runner = function($options = array()) use ($path, $params) {
			error_reporting(E_ALL | E_STRICT | E_DEPRECATED);
			return Dispatcher::run($path, $params + $options);
		};
		$report = $handlers[$this->format]($runner, $path);
		$stats = $report->stats();
		return $stats['success'];
	}

	/**
	 * Finds a library for given path.
	 *
	 * @param string $path Normalized (to slashes) absolute or relative path.
	 * @return string the name of the library
	 */
	protected function _library($path) {
		foreach (Libraries::get() as $name => $library) {
			if (strpos($path, $library['path']) !== 0) {
				continue;
			}
			return $name;
		}
	}

	/**
	 * Validates an absolute or relative path to test cases.
	 *
	 * @param string $path The directory or file path to one or more test cases
	 * @return string Returns a fully-resolved physical path, or `false`, if an error occurs.
	 */
	protected function _path($path) {
		$path = str_replace('\\', '/', $path);

		if (!$path) {
			$this->error('Please provide a path to tests.');
			return false;
		}
		if ($path[0] == '/') {
			$library = $this->_library($path);
		}
		if ($path[0] != '/') {
			$library = basename($this->request->env('working'));
			$parts = explode('/', str_replace("../", "", $path));
			$plugin = array_shift($parts);

			if ($plugin == 'libraries') {
				$plugin = array_shift($parts);
			}
			if ($plugin != 'tests') {
				$library = $plugin;
				$path = join('/', $parts);
			}
		}
		if (empty($library)) {
			$this->error("No library found in `{$path}`.");
			return false;
		}
		if (!$config = Libraries::get($library)) {
			$this->error("Library `{$library}` does not exist.");
			return false;
		}
		$path = str_replace($config['path'], null, $path);
		$realpath = $config['path'] . '/' . $path;

		if (!realpath($realpath)) {
			$this->error("{$realpath} not found.");
			return false;
		}
		$class = str_replace(".php", "", str_replace('/', '\\', ltrim($path, '/')));
		return $config['prefix'] . $class;
	}
}

?>