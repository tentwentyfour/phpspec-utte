<?php

/**
 * PHPSpec Result Parsing utility.
 *
 * For reference, see @{class:PhpunitTestEngine}.
 */
final class ArcanistPhpSpecTestResultParser extends ArcanistTestResultParser
{
    /**
     * Parse test results from phpunit json report
     *
     * @param string $testResults String containing phpspec xml report.
     *
     * @return array
     */
    public function parseTestResults($_, $testResults)
    {
        if (!$testResults) {
            $result = (new ArcanistUnitTestResult())
            ->setName("Tests")
            ->setUserData($this->stderr)
            ->setResult(ArcanistUnitTestResult::RESULT_BROKEN);

            return [$result];
        }

        $report = $this->getJunitReport($testResults);

        $results = [];

        foreach ($report->testsuite as $suite) {
            foreach ($suite->testcase as $test) {
                $result = new ArcanistUnitTestResult();

                $result->setName($suite["name"] . ": " . $test["name"]);

                if ((string) $test["status"] === "passed") {
                    $result->setResult(ArcanistUnitTestResult::RESULT_PASS);
                } else if ((string) $test["status"] === "skipped") {
                    $result->setResult(ArcanistUnitTestResult::RESULT_SKIP);
                } else {
                    $this
                    ->setFailureDetails($result, $test)
                    ->setResult(ArcanistUnitTestResult::RESULT_FAIL);
                }

                $result->setDuration(floatval($test["time"]));

                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * When a test has failed, this adds info about the failure
     * on the Arcanist test result object.
     *
     * @param ArcanistUnitTestResult $result The result where the failure info will be set. This
     *                                       is an output variable.
     * @param simplexml              $test   The failing test.
     *
     * @return ArcanistUnitTestResult The arcanist test result, for chaining purposes.
     */
    private function setFailureDetails($result, $test)
    {
        $failureInfo = null;

        $failureInfo = $test->failure["type"] . ": " . $test->failure["message"];

        $result->setUserData($failureInfo);

        return $result;
    }

    /**
     * Converts the raw PHPSpec output into a simplexml
     * document in the JUnit format.
     *
     * @param string $xml String containing JSON report.
     *
     * @return simplexml XML node containing the entire test results in the JUnit format.
     */
    private function getJunitReport($xml)
    {
        if (empty($xml)) {
            throw new Exception(
                pht(
                    'XML report file is empty, it probably means that phpspec '.
                    'failed to run tests. Try running %s with %s option and then run '.
                    'generated phpunit command yourself, you might get the answer.',
                    'arc unit',
                    '--trace'
                )
            );
        }

        return simplexml_load_string($xml);
    }
}
