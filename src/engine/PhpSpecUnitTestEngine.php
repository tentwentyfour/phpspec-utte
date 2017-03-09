<?php
/**
 * Based on Phacility's PHPUnitTestEngine
 *
 * @author  David Raison <david@tentwentyfour.lu>
 */

/**
 * PhpSpec wrapper.
 */
final class PhpSpecUnitTestEngine extends ArcanistUnitTestEngine {

  private $configFile;
  private $phpspecBinary = 'phpspec';
  private $affectedTests;
  private $projectRoot;

  public function run() {
    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
    $this->affectedTests = [];
    foreach ($this->getPaths() as $path) {

      $path = Filesystem::resolvePath($path, $this->projectRoot);

      // TODO: add support for directories
      // Users can call phpspec on the directory themselves
      if (is_dir($path)) {
        continue;
      }

      // Not sure if it would make sense to go further if
      // it is not a .php file
      if (substr($path, -4) != '.php') {
        continue;
      }

      if (substr($path, -8) == 'Spec.php') {
        // Looks like a valid test file name.
        $this->affectedTests[$path] = $path;
        continue;
      }

      if ($test = $this->findTestFile($path)) {
        $this->affectedTests[$path] = $test;
      }

    }

    if (empty($this->affectedTests)) {
      throw new ArcanistNoEffectException(pht('No tests to run.'));
    }

    $this->prepareConfigFile();
    $futures = [];
    $tmpfiles = [];
    foreach ($this->affectedTests as $class_path => $test_path) {
      if (!Filesystem::pathExists($test_path)) {
        continue;
      }
      $config = $this->configFile ? csprintf('-c %s', $this->configFile) : null;
      // getRenderer() ?
      $format = csprintf('-f junit');

      $futures[$test_path] = new ExecFuture(
        '%C run %C %C %s',
        $this->phpspecBinary,
        $config,
        $format,
        $test_path
      );

      // Only if phpspec will write files
      // $tmpfiles[$test_path] = [
      //   'json' => $json_tmp,
      //   'clover' => $clover_tmp,
      // ];

    }

    $results = [];
    $futures = id(new FutureIterator($futures))
      ->limit(4);
    foreach ($futures as $test => $future) {

      list($err, $stdout, $stderr) = $future->resolve();

      var_dump($err, $stdout, $stderr);

      // if it writes files, pass in $tmpfile, otherwise
      // pass in $stdout ?!
      // $results[] = $this->parseTestResults(
      //   $test,
      //   // $tmpfiles[$test]['json'],
      //   $stderr
      // );
    }

    return array_mergev($results);
  }

  /**
   * Parse test results from phpunit json report.
   *
   * @param string $path Path to test
   * @param string $json_tmp Path to phpunit json report
   * @param string $clover_tmp Path to phpunit clover report
   * @param string $stderr Data written to stderr
   *
   * @return array
   */
  private function parseTestResults($path, $json_tmp, $stderr) {
    $test_results = Filesystem::readFile($json_tmp);
    return id(new ArcanistPhpSpecTestResultParser())
      ->setEnableCoverage($this->getEnableCoverage())
      ->setProjectRoot($this->projectRoot)
      ->setAffectedTests($this->affectedTests)
      ->setStderr($stderr)
      ->parseTestResults($path, $test_results);
  }


  /**
   * Search for test cases for a given file in a large number of "reasonable"
   * locations. See @{method:getSearchLocationsForTests} for specifics.
   *
   * @param   string      PHP file to locate test cases for.
   * @return  string|null Path to test cases, or null.
   */
  private function findTestFile($path) {
    $root = $this->projectRoot;
    $path = Filesystem::resolvePath($path, $root);

    $file = basename($path);
    $possible_files = [
      $file,
      substr($file, 0, -4).'Spec.php',
    ];

    $search = self::getSearchLocationsForTests($path);

    foreach ($search as $search_path) {
      foreach ($possible_files as $possible_file) {
        $full_path = $search_path.$possible_file;
        if (!Filesystem::pathExists($full_path)) {
          // If the file doesn't exist, it's clearly a miss.
          continue;
        }
        if (!Filesystem::isDescendant($full_path, $root)) {
          // Don't look above the project root.
          continue;
        }
        if (0 == strcasecmp(Filesystem::resolvePath($full_path), $path)) {
          // Don't return the original file.
          continue;
        }
        return $full_path;
      }
    }

    return null;
  }


  /**
   * Get places to look for PHPSpec tests that cover a given file. For some
   * file "/a/b/c/X.php", we look in the same directory:
   *
   *  /a/b/c/
   *
   * We then look in all parent directories for a directory named "spec/"
   * (or "Spec/"):
   *
   *  /a/b/c/tests/
   *  /a/b/tests/
   *  /a/tests/
   *  /tests/
   *
   * We also try to replace each directory component with "spec/":
   *
   *  /a/b/tests/
   *  /a/tests/c/
   *  /tests/b/c/
   *
   * We also try to add "spec/" at each directory level:
   *
   *  /a/b/c/tests/
   *  /a/b/tests/c/
   *  /a/tests/b/c/
   *  /tests/a/b/c/
   *
   * This finds tests with a layout like:
   *
   *  docs/
   *  src/
   *  spec/
   *
   * ...or similar. This list will be further pruned by the caller; it is
   * intentionally filesystem-agnostic to be unit testable.
   *
   * @param   string        PHP file to locate test cases for.
   * @return  list<string>  List of directories to search for tests in.
   */
  public static function getSearchLocationsForTests($path) {
    $file = basename($path);
    $dir  = dirname($path);

    $test_dir_names = ['spec', 'Spec'];

    $try_directories = [];

    // Try in the current directory.
    $try_directories[] = [$dir];

    // Try in a spec/ directory anywhere in the ancestry.
    foreach (Filesystem::walkToRoot($dir) as $parent_dir) {
      if ($parent_dir == '/') {
        // We'll restore this later.
        $parent_dir = '';
      }
      foreach ($test_dir_names as $test_dir_name) {
        $try_directories[] = [$parent_dir, $test_dir_name];
      }
    }

    // Try replacing each directory component with 'spec/'.
    $parts = trim($dir, DIRECTORY_SEPARATOR);
    $parts = explode(DIRECTORY_SEPARATOR, $parts);
    foreach (array_reverse(array_keys($parts)) as $key) {
      foreach ($test_dir_names as $test_dir_name) {
        $try = $parts;
        $try[$key] = $test_dir_name;
        array_unshift($try, '');
        $try_directories[] = $try;
      }
    }

    // Try adding 'spec/' at each level.
    foreach (array_reverse(array_keys($parts)) as $key) {
      foreach ($test_dir_names as $test_dir_name) {
        $try = $parts;
        $try[$key] = $test_dir_name.DIRECTORY_SEPARATOR.$try[$key];
        array_unshift($try, '');
        $try_directories[] = $try;
      }
    }

    $results = [];
    foreach ($try_directories as $parts) {
      $results[implode(DIRECTORY_SEPARATOR, $parts).DIRECTORY_SEPARATOR] = true;
    }

    return array_keys($results);
  }

  /**
   * Tries to find and update phpunit configuration file based on
   * `phpunit_config` option in `.arcconfig`.
   */
  private function prepareConfigFile() {
    $project_root = $this->projectRoot.DIRECTORY_SEPARATOR;
    $config = $this->getConfigurationManager()->getConfigFromAnySource(
      'phpspec_config');

    if ($config) {
      if (Filesystem::pathExists($project_root.$config)) {
        $this->configFile = $project_root.$config;
      } else {
        throw new Exception(
          pht(
            'PHPUnit configuration file was not found in %s',
            $project_root.$config));
      }
    }
    $bin = $this->getConfigurationManager()->getConfigFromAnySource(
      'unit.phpspec.binary');
    if ($bin) {
      if (Filesystem::binaryExists($bin)) {
        $this->phpspecBinary = $bin;
      } else {
        $this->phpspecBinary = Filesystem::resolvePath($bin, $project_root);
      }
    }
  }

}
