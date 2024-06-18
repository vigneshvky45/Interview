<?php
namespace Symplicity\BehatSteps;

use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\DocumentElement;
use Behat\MinkExtension\Context\MinkContext;
use Symfony\Component\Console\Input\ArgvInput;
define('CSMDIR', dirname(dirname(dirname(dirname(__DIR__)))));

class FeatureContext extends MinkContext
{
    private $previous_key;

    protected static $is_first_scenario;
    protected static $current_role;
    protected static $current_session;
    protected static $current_section;
    protected static $current_subsection;

    protected $currentPageText;
    protected $stepsSinceCurrentPageTextUpdated = 1;
    protected $current_status_message;
    protected $timer = 0;
    protected $currentScenario;
    protected $outputPath = '';
    protected $locale = 'en';
    protected $browserTab = false;

    public function setLocale($locale)
    {
        $this->locale = $locale;
    }

    protected function videoSetup(): void
    {
        if (self::$is_first_scenario) {
            try {
                $driver = $this->getSession()->getDriver();
                if (is_callable([$driver, 'getWebDriverSessionId'])) {
                    $capabilities = $driver->getDesiredCapabilities();
                    if ($capabilities['video'] === 'True') {
                        $sessionId = $driver->getWebDriverSessionId();
                        if (!defined('VIDEO_RECORDING_URL')) {
                            define(
                                'VIDEO_RECORDING_URL',
                                'http://s3.amazonaws.com/4ad4a405-ef2a-b3d3-a629-1ab0a2d338b1/fda48e9a-b6c4-61bc-493f-17dbb0098ed6/play.html?' . $sessionId
                            );
                        }
                        echo 'Video being recorded at: ' . VIDEO_RECORDING_URL;
                    }
                }
                $driver->maximizeWindow();
            } catch (\Exception $e) {
                // probably not a @javascript test
            }
        }
    }

    /**
     * Translate function for other language instances.
     */
    public function translate($key, $subkey = 'misc')
    {
        static $dictionary = [];
        $locale = $this->locale;
        if ($locale === 'en') {
            $translation = preg_replace('/{|}/', '', $key);
        } else {
            if (!isset($dictionary[$locale])) {
                $dictionary[$locale] = $this->getDictionaryFromFile($locale);
            }
            $previous_label = [
                'Class Level',
                'Degree Level',
                'Desired Class Level',
                'Desired Work Authorization',
                'Event Type',
                'Fee Model',
                'Industry',
                'Information Session Type',
                'major',
                'Majors/Concentrations',
                'Work Authorization',
                'Type of Organization',
                'Number of Employees'
            ];
            if (in_array($key, $previous_label)) {
                $this->previous_key = $key;
            }
            if ($key === 'Senior' && in_array($this->previous_key, ['Desired Class Level', 'Class Level'])) {
                $translation = $this->picklistDict($locale, 'year', $key);
            } elseif (isset($dictionary[$locale][$key])) {
                $d = $dictionary[$locale][$key];
                if (is_array($d)) {
                    $translation = $d[$subkey] ?? array_pop($d);
                } else {
                    $translation = $d;
                    if ($key === 'All Majors' && ($locale === 'pt_br' || $locale === 'es_419')) {
                        $translation = $this->picklistDict($locale, 'major', $key);
                    }
                }
            } elseif (preg_match('/\{(.*?)\}/', $key, $matched)) {
                $pattern = '/\{(.*?)\}/';
                $replace = '\{(.*?)\}';
                $key = '/' . preg_replace($pattern, $replace, $key) . '/';
                $result = array_values(preg_grep($key, array_keys($dictionary[$locale])));
                if (is_array($dictionary[$locale][$result[0]])) {
                    $translationValue = $dictionary[$locale][$result[0]][$subkey] ?? current($dictionary[$locale][$result[0]]);
                } else {
                    $translationValue = $dictionary[$locale][$result[0]];
                }
                $translation = preg_replace($pattern, $matched[1], $translationValue);
            } else {
                $label = $this->previous_key;
                $picklist_name = [
                    'Class Level' => 'year',
                    'Degree Level' => 'degree_level',
                    'Desired Class Level' => 'year',
                    'Desired Work Authorization' => 'work_authorization',
                    'Event Type' => 'event_type',
                    'Fee Model' => 'fee_model',
                    'Industry' => 'industry',
                    'Information Session Type' => 'presentation_type',
                    'major' => 'major',
                    'Majors/Concentrations' => 'major',
                    'Work Authorization' => 'work_authorization',
                    'Type of Organization' => 'organization_type',
                    'Number of Employees' => 'number_of_employees'
                ];
                if (isset($picklist_name[$label])) {
                    $translation = $this->picklistDict($locale, $picklist_name[$label], $key);
                } else {
                    $translation = $key;
                }
            }
        }
        if (strpos($key, '<b>') !== false) {
            $translation = strip_tags($translation);
        }
        return $translation;
    }

    private function picklistDict($locale, $picklist_name, $key)
    {
        $path = CSMDIR . '/lang/picklists/' . $locale . '/picklist_' . $picklist_name . '.csv';
        $translation = $key;
        // TODO: there should be a simpler way to fetch picks (and keep them in memory)
        if (($handle = fopen($path, 'rb')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (is_array($data)) {
                    $num = count($data);
                    for ($c = 0; $c < $num; $c++) {
                        if ($data[$c] === $key) {
                            $translation = $data[$c + 1];
                            break;
                        }
                    }
                }
            }
            fclose($handle);
        }
        return $translation;
    }

    public function getDictionaryFromFile($locale)
    {
        $path = $this->getDictionaryPath($locale);
        if (is_file($path)) {
            return include $path;
        }
        return [];
    }

    public function getDictionaryPath($locale): string
    {
        return CSMDIR . '/lang/dictionary.' . $locale . '.lang.php';
    }

    /** @BeforeFeature */
    public static function beforeFeature($event)
    {
        self::$is_first_scenario = true;
        self::$current_session = null;
        self::$current_section = null;
        self::$current_subsection = null;
    }

    /** @BeforeScenario */
    public function beforeScenario($event)
    {
        $this->currentScenario = $event->getScenario();
        ++$this->stepsSinceCurrentPageTextUpdated;
    }

    /**
     * @BeforeStep
     */
    public function beforeStep(BeforeStepScope $scope)
    {
        ++$this->stepsSinceCurrentPageTextUpdated;
    }

    /**
     * @AfterStep
     */
    public function afterStep(AfterStepScope $scope)
    {
        static $skipPassingScreenshots;

        if ($scope->getTestResult()->isPassed()) {
            if (is_null($skipPassingScreenshots)) {
                $driver = $this->getSession()->getDriver();
                $capabilities = $driver->getDesiredCapabilities();
                $skipPassingScreenshots = (
                    empty($capabilities['passing_screenshots']) ||
                    (strtolower($capabilities['passing_screenshots']) === 'false')
                );
            }
            if ($skipPassingScreenshots) {
                return;
            }
        } else {
            $jsErrors = $this->getJsErrors();
            if ($jsErrors) {
                echo "JavaScript Errors Detected:\n$jsErrors";
            }
        }
        if (!$this->outputPath) {
            $input = new ArgvInput();
            $this->outputPath = $input->getParameterOption(['--out', '-o'], 'wwwdocs/features');
            if (!file_exists($this->outputPath)) {
                $this->outputPath = str_replace('wwwdocs/', 'web/', $this->outputPath);
            }
        }
        $featureFolder = preg_replace('/\W/', '', $scope->getFeature()->getTitle());

        $scenarioName = $this->currentScenario->getTitle();
        $step = $scope->getStep()->getLine();
        $fileName = md5($scenarioName . $step) . '.png';
        $folderPath = CSMDIR . '/' . $this->outputPath . '/assets/screenshots/' . $featureFolder;

        if (!file_exists($folderPath)) {
            $this->makeDirs($folderPath);
        }

        try {
            file_put_contents($folderPath . '/' . $fileName, $this->getSession()->getDriver()->getScreenshot());
            if (!$scope->getTestResult()->isPassed()) {
                $html = str_replace(
                    'var csmState= {"id":null,"location":',
                    'var csmState= {"id":null,"behat_location":',
                    $this->getSession()->getPage()->getOuterHtml()
                );
                file_put_contents(
                    $folderPath . '/' . str_replace('.png', '.html', $fileName),
                    $this->getSession()->getPage()->getOuterHtml()
                );
            }
        } catch (\Exception $e) {
            // I guess we will just not have a screenshot for this step
        }
    }

    public function getJsErrors(): string
    {
        $result = '';
        try {
            $jsErrors = $this->getSession()->evaluateScript(
                "return window.behatErrors ? JSON.stringify(window.behatErrors) : false;"
            );
            if ($jsErrors) {
                $jsErrors = json_decode($jsErrors, true);
                if (!empty($jsErrors)) {
                    $result = implode("\n", $jsErrors);
                }
            }
        } catch (\Exception $e) {
            // could not get errors
        }
        return $result;
    }

    private function makeDirs($strPath): bool
    {
        if (file_exists($strPath)) {
            return false;
        }

        $strPathSeparator = '/';
        if ($strPath[strlen($strPath) - 1] !== $strPathSeparator) {
            $strPath .= $strPathSeparator;
        }

        $strDirname = substr($strPath, 0, strrpos($strPath, $strPathSeparator));
        if (is_dir($strDirname)) {
            return true;
        }

        $arMake = [];
        array_unshift($arMake, $strDirname);
        do {
            $bStop = true;
            $nPos = strrpos($strDirname, $strPathSeparator);
            $strParent = substr($strDirname, 0, $nPos);
            if (!is_dir($strParent)) {
                $strDirname = $strParent;
                array_unshift($arMake, $strDirname);
                $bStop = false;
            }
        } while (!$bStop);
        if (count($arMake) > 0) {
            foreach ($arMake as $strDir) {
                mkdir($strDir);
            }
        }
        return true;
    }

    /** @AfterScenario */
    public function afterScenario($event)
    {
        self::$is_first_scenario = false;

        if ($this->browserTab) {
            $window = $this->getSession()->getWindowName();
            $this->getSession()->stop($window);
            $this->browserTab = false;
        }
    }

    protected function underscore($label)
    {
        return strtolower(str_replace(' ', '_', $label));
    }

    public function pageContainsText($text, int $spins = 40)
    {
        $text = $this->fixStepArgument($text);
        $this->pageTextContains($text);
    }
    
    public function pageTextContains($text)
    {
        $this->updateCurrentPageText();
        $this->iShouldAlsoSee($text);
    }

    private function updateCurrentPageText()
    {
        if ($this->stepsSinceCurrentPageTextUpdated > 1) {
            $this->getSession()->getDriver()->executeScript('window = function(){return true;}');
            $actual = $this->getSession()->getPage()->getText();
            $this->currentPageText = preg_replace('/\s+/u', ' ', $actual);
            $this->stepsSinceCurrentPageTextUpdated = 0;
        }
    }

    public function pageTextNotContains($text)
    {
        $this->updateCurrentPageText();
        $this->iShouldAlsoNotSee($text);
    }

    /**
     * @Then I should also see :text
     */
    public function iShouldAlsoSee($text)
    {
        $regex = '/'.preg_quote($text, '/').'/ui';
        if (!preg_match($regex, $this->currentPageText)) {
            $message = sprintf('The text "%s" was not found anywhere in the text of the current page.', $text);
            throw new \Exception($message);
        }
    }

    /**
     * @Then I should also not see :text
     */
    public function iShouldAlsoNotSee($text)
    {
        $regex = '/'.preg_quote($text, '/').'/ui';
        if (preg_match($regex, $this->currentPageText)) {
            $message = sprintf('The text "%s" appears in the text of this page, but it should not.', $text);
            throw new \Exception($message);
        }
    }

    public function assertPageContainsText($text)
    {
        $text = $this->translate($text);
        $this->pageTextContains($text);
    }

    public function assertPageNotContainsText($text)
    {
        $text = $this->translate($text);
        $this->pageTextNotContains($text);
    }
    /**
     * @Then I will see :text
     */
    public function iWillSee($text)
    {
        // $page = $this->getSession()->getPage();
        $text = $this->translate($text);
        $this->spin(static function ($context) use ($text) {
            $context->stepsSinceCurrentPageTextUpdated = 2;
            $context->pageContainsText($text);
            return true;
        });
    }

    /**
     * @Then I will not see :text
     */
    public function iWillNotSee($text)
    {
        $text = $this->translate($text);
        $this->spin(static function ($context) use ($text) {
            $context->stepsSinceCurrentPageTextUpdated = 2;
            $context->pageTextNotContains($text);
            return true;
        });
    }

    /**
     * @When I open :record record
     */
    public function iOpenRecord($record)
    {
        if (in_array(strtolower($record), ['first', ','])) {
            $this->clickXpath('//th[last()]/following::td[1]//a');
        } else {
            $this->iClickLink($record);
        }
    }

    protected function openMenu($name, $is_open_css = ''): DocumentElement
    {
        $page = $this->getSession()->getPage();
        if ($is_open_css && $page->find('css', $is_open_css)) {
            return $page;
        }
        $this->spin(static function () use ($page, $name) {
            $menu = $page->find('css', $name);
            if ($menu) {
                $menu->click();
                return true;
            }
            throw new \Exception($name . ' menu not found.');
        });
        return $page;
    }

    /**
     * @Given I am logged in as :role
     */
    public function iAmLoggedInAs($role)
    {
        if (!self::$current_session || (self::$current_role !== $role)) {
            // Needs to be defined in the app
            $this->iAmLoggedIn($role);
            self::$current_role = $role;
            $driver = $this->getSession()->getDriver();
            self::$current_session = $driver->getCookie('PHPSESSID');
        }
    }

    /**
     * @When I scroll to :elementName field
     */
    public function iScrollToField($elementName)
    {
        $function = <<<JS
        (function(){
            let elem = document.getElementsByName('$elementName')[0];
            elem.scrollIntoView(false);
        })()
JS;
        try {
            return $this->getSession()->executeScript($function);
        } catch (Exception $e) {
            throw new \Exception("Failed to scroll to $elementName");
        }
    }

    protected function scrollToElement(string $path)
    {
        $function = <<<JS
            let element = null;
            for (let i = 0; i < 10; i++) {
                element = document.evaluate(`$path`, document.body, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null).snapshotItem(0);
                if (element !== null) {
                    element.scrollIntoView({block: "center", inline: "center"});
                    break;
                }
                setTimeout(()=>{}, 1000);
            }
JS;
        $this->getSession()->executeScript($function);
    }

    public function forceClickXpath(string $path)
    {
        $function = <<<JS
            let element = document.evaluate(`$path`, document.body, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null).snapshotItem(0);
            if (element !== null) {
                element.click();
            }
JS;
        return $this->getSession()->executeScript($function);
    }

    /**
     * @When /^I click "([^"]*)" button$/
     */
    public function useButton($label)
    { 
        $label = $this->translate($label);
        $this->spin(static function ($context) use ($label) {
            $context->pressButton($label);
            return true;
        });
    }

    /**
     * @When I click :button button and confirm
     */
    public function clickButtonAndConfirm($button)
    {
        $this->getSession()->getDriver()->executeScript('window.confirm = function(){return true;}');
        $this->useButton($button);
    }

    /**
     * @When /^I click "([^"]*)" link$/
     */
    public function iClickLink($link, $css = '')
    {
        $page = $this->getSession()->getPage();
        $this->clickPageLink($link, $page, $css);
    }

    /**
     * @When I click :link link and confirm
     */
    public function clickLinkAndConfirm($link)
    {
        $this->getSession()->getDriver()->executeScript('window.confirm = function(){return true;}');
        $this->iClickLink($link);
    }

    protected function clickPageLink($link, $page, $css = '')
    {
        $this->spin(function () use ($link, $page, $css) {
            if ($css) {
                $link_el = $page->find('css', $css);
            } else {
                $link_el = null;
                $links = $page->findAll(
                    'xpath',
                    "//a[(./@id = '$link' or normalize-space(string(.)) = '$link' or ./@title = '$link')] | //button[@aria-label='$link']"
                );
                foreach ($links as $l) {
                    if ($l->isVisible()) {
                        $link_el = $l;
                        break;
                    }
                }
                if (!$link_el) {
                    $link_el = $page->findLink($link);
                }
            }
            if ($link_el) {
                $link_el->click();
                $this->iWillSeeLoadingIndicator();
                return true;
            }
            throw new \Exception($link . ' link not found.');
        });
    }

    public function clickXpath(string $xpath, int $spins = 50)
    {
        try {
            $el = $this->findXpathElement($xpath, $spins);
            $el->focus();
            $el->click();
            echo " clickXapth try";
        } catch (\Exception $e) {
            echo " clickXapth catch";
            $page = $this->getSession()->getPage();
            $el = $page->find('xpath', $xpath);
            if (!$el) {
                throw $e;
            }
            $this->forceClickXpath($xpath);
        }
        $this->iWillSeeLoadingIndicator();
    }

    public function focusAndClick(string $xpath, $element = null)
    {
        if (!$element) {
            $element = $this->findXpathElement($xpath);
        }
        try {
            $element->focus();
            $element->click();
        } catch (\Exception $e) {
            $this->forceClickXpath($xpath);
        }
    }

    protected function findXpathElement(string $xpath, int $spins = 50)
    {
        $page = $this->getSession()->getPage();
        return $this->spin(function () use ($page, $xpath) {
            $elem = $page->find('xpath', $xpath);
            if ($elem) {
                if (is_array($elem)) {
                    foreach ($elem as $el) {
                        if ($el->isVisible()) {
                            break;
                        }
                    }
                    return $el;
                }
                return $elem;
            }
            throw new \Exception("Could not find $xpath");
        }, $spins);
    }

    protected function selectDate($field, $date)
    {
        $day = date('d', strtotime($date));
        $this->clickElementWithStyle('#' . $field);
        $this->clickElementWithStyle(".datepicker button:contains('$day') span:not(.text-muted)");
    }

    protected function clickElementWithStyle($css, int $spins = 50)
    {
        $page = $this->getSession()->getPage();
        $this->spin(static function () use ($page, $css) {
            if ($elems = $page->findAll('css', $css)) {
                foreach ($elems as $el) {
                    if (!$el->hasAttribute('disabled')) {
                        $el->click();
                        return true;
                    }
                }
            }
            throw new \Exception("Could not find element with $css");
        }, $spins);
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" tab$/
     */
    public function iSeeTab($not, $tab)
    {
        $tabLink = $this->findTab($tab);
        if ($tabLink) {
            if ($not) {
                throw new \Exception('$tab tab found');
            }
        } elseif (!$not) {
            throw new \Exception("$tab tab not found");
        }
    }

    protected function findTab($tab)
    {
        $page = $this->getSession()->getPage();
        $css = $this->getTabBarStyle();
        $titlebar = $page->find('css', $css);
        if (!$titlebar) {
            throw new \Exception("Titlebar not found for $tab");
        }
        return $titlebar->findLink($tab);
    }

    protected function getTabBarStyle(): string
    {
        return 'div.titlebar';
    }

    /**
     * @When I open :tab tab
     */
    public function iOpenTab($tab)
    {
        $tabLink = $this->findTab($tab);
        if ($tabLink) {
            $tabLink->click();
        } else {
            throw new \Exception("Tab $tab not found");
        }
    }

    /**
     * @Given /^I fill "([^"]*)" form with:$/
     */
    public function iFillFormWith($form, TableNode $records)
    {
        foreach ($records->getHash() as $r) {
            foreach ($r as $field => $value) {
                $this->fillField($field, $value);
            }
        }
    }

    public function spin($lambda, int $spins = 50)
    {
        for ($i = 0; $i < $spins; $i++) {
            try {
                if ($result = $lambda($this)) {
                    return $result;
                }
            } catch (\Exception $e) {
                usleep(400000);
            }
        }
        if (!isset($e)) {
            $backtrace = debug_backtrace();
            $e = new \Exception('Timeout in ' . $backtrace[1]['class'] . '::' . $backtrace[1]['function']);
        }
        throw $e;
    }

    /**
     * @When /^I click the back button$/
     */
    public function iClickBack()
    {
        $this->getSession()->back();
        $this->iWillSeeLoadingIndicator();
    }

    /**
     * @When /^I click "([^"]*)" option$/
     */
    public function iClickOption($option)
    {
        $this->iClickLink($option);
    }

    /**
     * @Then I click on :text
     */
    public function iClickOn($text, int $spins = 40)
    {
        $element = $this->findXpathElement("//*[contains(text(),'$text')]", $spins);
        $element->click();
    }

    protected function iSelectAutocompleteOption($field, $val)
    {
        $this->fillField($field, $val);
        $this->iWillSeeLoadingIndicator();
        $this->clickElementWithStyle('.angucomplete-row');
    }

    /**
     * To debug a behat step, call $this->takeScreenshot('foo');
     * That will create web/features/foo.png
     */
    protected function takeScreenshot($filename)
    {
        $screenshot = $this->getSession()->getScreenshot();
        file_put_contents(APP_BASE . '/wwwdocs/features/' . $filename . '.png', $screenshot);
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" button$/
     */
    public function iShouldSeeButton($not, $label)
    {
        $page = $this->getSession()->getPage();
        $element = ($page->find('xpath', "//input[@value='" . $label . "']") || $page->find('xpath', "//button[text()='" . $label . "']"));
        if ((!$element) xor $not) {
            throw new \Exception(($not ? 'Found' : 'Could not find') . " $label button.");
        }
    }

    /**
     * @When I use :action action
     */
    public function iUseAction($action)
    {
        $this->iClickLink($action, $this->getActionStyle($action));
    }

    /**
     * @When I use :tab tab action
     * @When I use :tab tab action :action
     */
    public function iUseTabAction($tab, $action = '')
    {
        $page = $this->iClickLink($tab, $this->getTabActionStyle($tab));
        if ($action) {
            $this->clickPageLink($action, $page);
        }
    }

    /**
     * @Given I enter value :val into :field richtext field
     */
    public function iEnterValueIntoRichtextField($val, $field)
    {
        $tinymce = $this->getSession()->getPage()->find('css', '.mce-tinymce');
        if ($tinymce) {
            $this->getSession()->executeScript("tinymce.get()[0].setContent('$val');");
        } else {
            throw new \Exception("Could not find '$field' richtext field");
        }
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" in rich text editor$/
     */
    public function iShouldSeeRichText($not, $text)
    {
        $content = strip_tags($this->getSession()->evaluateScript("tinymce.get()[0].getContent();"));
        if ((strpos($content, $text) === false) xor $not) {
            throw new \Exception('Text ' . $text . ($not ? '' : ' not') . ' appear in ' . $content);
        }
    }

    protected function postValueToXpath($xpath, $value)
    {
        $session = $this->getSession();
        $driver = $session->getDriver();
        if (is_callable([$driver, 'getWebDriverSession'])) {
            $session = $driver->getWebDriverSession();
        }
        $el = $session->element('xpath', $xpath);
        // this method keeps the focus on the field
        $el->postValue(['value' => [$value]]);
    }

    /**
     * @Given /^I will see an? (\w+) message$/
     */
    public function iWillSeeStatusMessage($status)
    {
        if ($this->current_status_message === $status) {
            $this->current_status_message = null;
            return true;
        }
        $page = $this->getSession()->getPage();
        $this->current_status_message = null;
        $this->spin(static function ($context) use ($page, $status) {
            if ($context->findStatusMessage($page, $status)) {
                return true;
            }
            throw new \Exception($status . ' status message not found.');
        });
    }

    protected function findStatusMessage($page, $status)
    {
        if ($this->current_status_message !== $status) {
            if ($page->find('css', '.alert-' . $status) || (
                ($status === 'error') && $page->find('css', '.alert-danger')
            )
            ) {
                try {
                    $this->clickElementWithStyle('.growl-item button.close', 10);
                } catch (\Exception $e) {
                    // already closed, carry on
                }
                $this->current_status_message = $status;
            }
        }
        return ($this->current_status_message === $status);
    }

    /**
     * @Then /^I will see loading indicator$/
     */
    public function iWillSeeLoadingIndicator()
    {
        $page = $this->getSession()->getPage();
        $cnt = 3;
        while (!$this->findLoadingIndicator($page) && ($cnt > 0)) {
            usleep(200);
            --$cnt;
        }
        $cnt = 100;
        while ($this->findLoadingIndicator($page) && ($cnt > 0)) {
            usleep(1000);
            --$cnt;
        }
    }

    protected function findLoadingIndicator($page): bool
    {
        return ($page->find('css', '.loading-bar') || $page->find('css', '.side-nav-menu-loading'));
    }

    protected function clickIfExists($path): bool
    {
        $found_something = false;
        if ($close_button = $this->getSession()->getPage()->find('xpath', $path)) {
            try {
                $close_button->click();
            } catch (\Exception $e) {
                // maybe next time
            }
            $found_something = true;
        }
        return $found_something;
    }

    /**
     * @Then /^I should see "([^"]*)" before "([^"]*)"$/
     */
    public function iShouldSeeBefore($before, $after)
    {
        $this->spin(function () use ($before, $after) {
            $this->assertPageMatchesText("/$before.*$after/");
            return true;
        });
    }

    /**
     * @Given /^I click "([^"]*)" button on modal$/
     */
    public function iClickButtonOnModal($label)
    {
        $translatedLabel = $this->translate($label);
        $css = '.modal';
        $page = $this->getSession()->getPage();
        $button = $page->find('css', "$css-footer button:contains('$translatedLabel')");
        if (!$button) {
            echo "here";
            // in case it is not a dock modal
            $button = $page->find('css', "$css button:contains('$translatedLabel')");
            if (!$button) {
                $button = $page->find('xpath', "//div[contains(@class, 'modal-content')]//button[text()='$translatedLabel' or @aria-label='$translatedLabel' or @title='$translatedLabel'] | //div[contains(@class, 'modal-content')]//span[text()='$translatedLabel' or @aria-label='$translatedLabel' or @title='$translatedLabel']");
            }
        }
        if ($button) {
            echo "Button is available";
            $button->click();
        } elseif (strpos($label, '_of_') !== false) {
            [$action, $title] = explode('_of_', $label);
            $translatedLabel = $this->translate($action);
            $this->clickXpath("//div[contains(@class, 'modal-content')]//*[normalize-space(text()='$title')]/following::button[normalize-space(text()='$translatedLabel') or @aria-label='$translatedLabel' or @title='$translatedLabel']");
        } else {
            throw new \Exception("Could not find $translatedLabel button on modal.");
        }
    }

    /**
     * @Given /^I use "([^"]*)" button on modal$/
     */
    public function iUseButtonOnModal($label)
    {
        $this->iClickButtonOnModal($label);
        $this->iWillSeeLoadingIndicator();
    }

    /**
     * @Given /^I should (not )?see "([^"]*)" in modal$/
     */
    public function iShouldSeeInModal($not, $label)
    {
        $page = $this->getSession()->getPage();
        $label = $this->translate($label);
        $xpath = "//div[contains(@class, 'modal')]//*[contains(text(), \"$label\")]";
        $button = $page->find('xpath', $xpath);
        if (!($button xor $not)) {
            for ($i = 0; $i < 10; $i++) {
                if (!$not) {
                    $modal = $page->find('css', '.modal');
                    if (!$modal) {
                        break;
                    }
                }
                $button = $page->find('xpath', $xpath);
                if ($button xor $not) {
                    return;
                }
                usleep(400000);
            }
        }
        if (!($button xor $not)) {
            throw new \Exception(($not ? "Found" : "Could not find") . " a modal with text \"$label\"");
        }
    }

    public function fillField($field, $value)
    {
        if ($field === 'columnselector:search') {
            $value = $this->translate($value);
        }
        $element = $this->getSession()->getPage()->findField($field);
        if (!$element) {
            $field = $this->translate($field);
            $element = $this->getSession()->getPage()->findField($field);
        }
        if ($element) {
            echo "Element available";
            $element->setValue($value);
        } else {
            echo "Element not available";
            $this->spin(function () use ($field, $value) {
                parent::fillField($field, $value);
                return true;
            }, 5);
        }
    }

    public function selectOption($value, $field)
    {
        $field = $this->translate($field);
        if (strpos($value, "'") !== false) {
                $option = $this->getSession()->getPage()->find('xpath', "//option[text()=" . '"' . $value . '"' . "]");
            } else {
                echo "Else select option\n";
                $option = $this->getSession()->getPage()->find('xpath', "//option[contains(text(), '$value')]");
        } 
		if (!$option) {
                $value = $this->translate($value);
                    if (strpos($field, 'month_') !== false) {
                        try {
                            return parent::selectOption($field, $value);
                        } catch (\Exception $e) {
                            $value = lcfirst($value);
                        }
                    }
            }
        $this->spin(function () use ($field, $value) {
            parent::selectOption($field, $value);
            return true;
        });
    }

    /**
     * @when /^(?:|I )confirm the popup$/
     */
    public function confirmPopup()
    {
        // this function works in real browsers, but not in PhantomJS
        try {
            $driver = $this->getSession()->getDriver();
            if (is_callable([$driver, 'getWebDriverSession'])) {
                $driver->getWebDriverSession()->accept_alert();
            }
        } catch (\Exception $e) {
            // there was no alert
        }
    }

    /**
     * @when /^I confirm "([^"]*)" alert$/
     */
    public function confirmAlert($alert)
    {
        $driverSession = $this->getSession()->getDriver()->getWebDriverSession();
        $actualAlert = $driverSession->getAlert_text();
        if ($alert === $actualAlert) {
            $driverSession->accept_alert();
        } else {
            throw new \Exception("Expected alert '$alert', found '$actualAlert'");
        }
    }

    /**
     * @Then /^I switch to popup window$/
     */
    public function iSwitchToPopup()
    {
        $windowNames = $this->getSession()->getWindowNames();
        if (count($windowNames) > 1) {
            $this->getSession()->switchToWindow($windowNames[1]);
        }
    }

    /**
     * @Then /^I set main window name$/
     */
    public function iSetMainWindowName()
    {
        $window_name = 'main_window';
        $script = 'window.name = "' . $window_name . '"';
        $this->getSession()->executeScript($script);
    }

    /**
     * @Then /^I switch back to main window$/
     */
    public function iSwitchBackToMainWindow()
    {
        $this->getSession()->switchToWindow('main_window');
    }

    /**
     * @When (I )switch to iframe :name
     */
    public function switchToIFrame($name)
    {
        $iframeSelector = "iframe[id='$name'],iframe[name='$name'],frame[name='$name'],form[name='$name'],iframe[title='$name'],iframe[value='$name']";
        $function = <<<JS
            (function(){
                 var iframe = document.querySelector("$iframeSelector");
                 iframe.name = "iframeToSwitchTo";
            })()
JS;
        try {
            $this->getSession()->executeScript($function);
        } catch (\Exception $e) {
            throw new \Exception("Element $iframeSelector was NOT found." . PHP_EOL . $e->getMessage());
        }
        $this->getSession()->switchToIFrame('iframeToSwitchTo');
    }

    /**
     * @When (I )switch to main frame
     */
    public function switchToMainFrame()
    {
        $this->getSession()->switchToIFrame();
    }

    /**
     * @Then I switch to Browser Tab :tabnumber
     */
    public function iSwitchToBrowserTab($tabnumber, $retry = 15)
    {
        while ($retry) {
            $windowNames = $this->getSession()->getWindowNames() ?? [];
            if (isset($windowNames[$tabnumber])) {
                break;
            }
            $this->iWait(1000);
            $retry--;
        }
        if (!isset($windowNames[$tabnumber])) {
            throw new \Exception(
                "Did not find browser tab $tabnumber. Available tabs are: "
                . join(', ', array_keys($windowNames))
            );
        }
        $this->iWait(1000);
        $this->getSession()->switchToWindow($windowNames[$tabnumber]);
        $this->browserTab = true;
    }

    /**
     * @When /^I wait (\d+) ms$/
     */
    public function iWait($time)
    {
        usleep((int) $time * 1000);
    }
    
    /**
     * @Then I restart the browser
     */
    public function restartBrowser()
    {
        $this->getSession()->restart();
    }

    /**
     * @Given there are no accessibility issues
     */
    public function thereAreNoAccessibilityIssues()
    {
        $this->getSession()->executeScript("
            window.axeReport = {
                'minor': [],
                'moderate': [],
                'serious': [],
                'critical': []
            };
            function runAxe() {
                window.axe.run({
                    runOnly: {
                        type: 'tag',
                        values: ['wcag2a', 'wcag2aa', 'section508']
                    },
                    'resultTypes': ['violations']
                },
                function (err, results) {
                    if (results.violations.length) {
                        window.axeReport.url = results.url;
                        results.violations.forEach(function(rule) {
                            if (window.axeReport[rule.impact]) {
                                window.axeReport[rule.impact].push({
                                    rule: rule.help.replace(/</g, '&lt;').replace(/>/g, '&gt;'),
                                    link: rule.helpUrl,
                                    nodes: rule.nodes.map(function(node) {
                                        return {
                                            failure: node.failureSummary.replace(/</g, '&lt;').replace(/>/g, '&gt;'),
                                            html: node.html.replace(/</g, '&lt;').replace(/>/g, '&gt;')
                                                .replace(/(\b(key|secret)=['\"]?)[^'\"\&]+/gi, '$1=...')
                                        };
                                    })
                                });
                            }
                        });
                    }
                });
            }
            if (window.axe) {
                runAxe();
            } else {
                var axeScript = window.document.createElement('script');
                axeScript.type = 'text/javascript';
                axeScript.src = 'https://static.symplicity.com/cdn/lib/axe-core/axe.min.js';
                axeScript.onload = function() {
                    runAxe();
                }
                window.document.body.appendChild(axeScript);
            }
        ");
        sleep(1);
        $axeReport = $this->getSession()->evaluateScript('return window.axeReport;');
        if (!$axeReport) {
            throw new \Exception('Could not run accessiblity report - perhaps axe-min.js was not included?');
        }
        if (empty($axeReport['url'])) {
            return;
        }
        $url = $axeReport['url'];
        $errors = '';
        $css = [
            'critical' => 'failed',
            'serious' => 'skipped',
            'moderate' => 'pending',
            'minor' => ''
        ];
        foreach (['critical', 'serious', 'moderate', 'minor'] as $impact) {
            $report = $this->getSession()->evaluateScript("return JSON.stringify(window.axeReport['$impact']);");
            if ($report) {
                $report = json_decode(
                    preg_replace('/(Fix (any|all) of the following:)/', '<em>$1</em>', $report),
                    true
                );
                if ($report) {
                    foreach ($report as $r) {
                        $cnt = count($r['nodes']);
                        $errors .= "<tr class='{$css[$impact]} row'>
                            <td rowspan='{$cnt}'><a href='{$r['link']}' target='_blank'>{$r['rule']}</a></td>\n";
                        foreach ($r['nodes'] as $ndx => $node) {
                            if ($ndx > 0) {
                                $errors .= "<tr class='{$css[$impact]} row'>";
                            }
                            $failures = preg_replace('/(\s*\n+\s*)+/', "<br>\n* ", trim($node['failure']));
                            $errors .= '<td>'
                                . str_replace('* <em>', '<em>', $failures)
                                . "<br>\n<code>{$node['html']}</code></td></tr>\n";
                        }
                    }
                }
            }
        }
        if ($errors) {
            // The <pre> tags are here to bust out of a <pre> block in the surround behat report
            throw new \Exception("Accessibility issues discovered at: $url</pre>
            <table style='padding:0; font-size:80%; background-color:white;'>
            <thead><tr class='failed row'><th>Rule</th><th>Issues</th></tr></thead><tbody>
            $errors</tbody></table><pre>");
        }
    }

    /**
     * @Then /^I will (not )?see columns in below order$/
     */
    public function iWillSeeColumnsInOrder($not = null, TableNode $table = null)
    {
        $page = $this->getSession()->getPage();
        foreach ($table->getHash() as $record) {
            $column = $this->translate($record['column']);
            $order = ((int) $record['order']);
            $column_elements = $page->findAll('xpath', "//table[@id='SQLReportTable']/thead//th | //table[@class='list_maincol']/tbody/tr[2]/th | //div[@class='chart-legend']/table/thead/tr/th");
            foreach ($column_elements as $key => $label) {
                $columnValue = $record['column'];
                $columnElement = $this->findXpathElement("//table[@id='SQLReportTable']/thead//th//span[text()='$columnValue'] | //table[@class='list_maincol']/tbody/tr[2]/th//span[text()='$columnValue'] | //div[@class='chart-legend']/table/thead/tr/th/div/p[text()='$columnValue']");
                if (mb_strstr($label->getText(), $column)) {
                    $matchedKeyPosition = ((int) $key) + 1;
                    if ((!$columnElement || $matchedKeyPosition !== $order) xor $not) {
                        throw new \Exception("$column Column is $not not in the mentioned order $order");
                    }
                    break;
                }
            }
        }
    }
    /**
     * @Given I assign an appointment :slot on :date date for :name
     */
    public function iAssignAnAppointmentOnDay($slot, $date, $name)
    {
        $page = $this->getSession()->getPage();
        $calMonth = $page->find('xpath', "//div[contains(@class,'yui-calcontainer single')]/table/thead/tr/th/div");
        $array = preg_split('/[ ,]+/', trim($calMonth->getText()));
        $arrayMonth = $array[4];
        $arrayYear = $array[5];
        $updatedDate = date("Y-F-j", strtotime($date));
        $date = explode('-', $updatedDate);
        $expectedYear = $date[0];
        $expectedMonth = $this->translate($date[1]);
        $expectedDate = $date[2];
            while ($arrayMonth !== $expectedMonth) {
                if (date('n', strtotime($arrayMonth)) < date('n', strtotime($expectedMonth))) {
                    $this->clickXpath("//div[@class='calheader']/a[@class='calnavright' and contains(text(), 'Next Month')]");
                } else {
                    $this->clickXpath("//div[@class='calheader']/a[@class='calnavleft' and contains(text(), 'Previous Month')]");
                }
                $calMonth = $page->find('xpath', "//div[contains(@class,'yui-calcontainer single')]/table/thead/tr/th/div");
                $array = preg_split('/[ ,]+/', trim($calMonth->getText()));
                $arrayMonth = $array[4];
            }
            if ($arrayYear !== $expectedYear) {
                $totalYear = $expectedYear - $arrayYear;
                $defaultMonthcount = 12;
                $totalMonths = $defaultMonthcount * $totalYear;
                $counter = 0;
                    if ($totalMonths < 0) {
                        $prevMonth = ($arrayYear - $expectedYear) * $defaultMonthcount;
                        $run = $prevMonth;
                    } else {
                        $run = $totalMonths;
                    }
                    while ($counter !== $run) {
                        if($totalMonths > 0){
                            $this->clickXpath("//div[@class='calheader']/a[@class='calnavright' and contains(text(), 'Next Month')]");
                        } elseif ($prevMonth > 0) {
                             $this->clickXpath("//div[@class='calheader']/a[@class='calnavleft' and contains(text(), 'Previous Month')]");
                        }
                        $counter++;
                    }
            }
            $this->clickXpath("//div[contains(@class,'yui-calcontainer single')]/table/tbody/tr/td/a[text()='$expectedDate']");
            $this->iWillSee($name);
            $this->iClickLink(strtolower($slot));
    }
}