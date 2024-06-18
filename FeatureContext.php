<?php
namespace Symplicity\CSM\Tests\Features\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use DateTime;

if (!defined('CSM_BASE')) {
    if (getenv('PWD')) {
        define('CSM_BASE', getenv('PWD'));
    } else {
        define('CSM_BASE', dirname(__DIR__, 3));
    }
}
include_once CSM_BASE . '/src/App.php';

class FeatureContext extends \Symplicity\BehatSteps\FeatureContext
{
    private $records = [];
    private $dateAsLabel;
    private $actualDate;
    private $startDate;
    private $endDate;
    private $customSkill;
    private $review;
    private $picklist;
    private $newPick;
    private $inAfterScenario = false;
    private $navigatedToSettings = false;
    private $changedSettings = false;
    private $settingsNav = [];
    private $modifiedSettings = [];
    private $role;
    private $profile_percent;
    private $ocrDateLabels = [];
    private $ocrDates = [];
    private $activeSection = false;
    private $activeTab = false;
    private $scenarioTimer;
    private $fileLocation;

    private static $tag;

    /**
     * @BeforeSuite
     */
    public static function prepareApp()
    {
        define('TEST_SCRIPT', true);
    }

    /** @BeforeScenario */
    public function before($event)
    {
        if (parent::$is_first_scenario) {
            $this->iAmLoggedOut();
            try {
                $driver = $this->getSession()->getDriver();
                if (is_callable([$driver, 'getWebDriverSessionId'])) {
                    $capabilities = $driver->getDesiredCapabilities();
                    if ($capabilities['video'] === 'True') {
                        $sessionId = $driver->getWebDriverSessionId();
                        define('VIDEO_RECORDING_URL',
                            'https://s3.amazonaws.com/4ad4a405-ef2a-b3d3-a629-1ab0a2d338b1/fda48e9a-b6c4-61bc-493f-17dbb0098ed6/play.html?' . $sessionId);
                        echo "Video being recorded at: " . VIDEO_RECORDING_URL;
                    }
                }
                $driver->maximizeWindow();
            } catch (\Exception $e) {
                // probably not a @javascript test
            }
        } elseif (parent::$current_session) {
            $this->getSession()->executeScript("document.cookie='PHPSESSID=" . parent::$current_session . "; path=/';");
        }
        $this->setLocale('en');
        $this->scenarioTimer = microtime(true);
    }

    /**
     * @Given /^I want to (\w+) the current session$/
     */
    public function setCurrentSession($action)
    {
        if ($action === 'remain') {
            parent::$current_session = $this->getSession()->getCookie('PHPSESSID');
        } elseif ($action === 'clean') {
            parent::$current_session = null;
        }
    }

    /**
     * @Given /^I am logged out$/
     */
    public function iAmLoggedOut()
    {
        $this->visit($this->locatePath('/logout.php'));
    }

    /**
     * @Given /^I go to (\w+) interface$/
     */
    public function iGoToInterface($interface)
    {
        $this->role = null;
        $this->iRetryUntilInterfaceLoaded($interface);
    }

    public function iRetryUntilInterfaceLoaded($interface)
    {
        $this->iAmLoggedOut();
        $this->visit($this->locatePath("/$interface/"));
        for ($retry = 0; ($this->getActiveInterface() !== $interface && $retry < 5); $retry++) {
            $this->visit($this->locatePath("/$interface/"));
            $this->iWait(1000);
        }
    }

    /**
     * @When I read profile completion percent
     */
    public function iReadProfileCompletionPercent()
    {
        $this->iMouseOver('view-student-name');
        $element = $this->findXpathElement("//div[@class='completion-percentage']/span");
        $read_percent = preg_replace('/%/', '', $element->getText());
        $this->profile_percent = $read_percent;
    }

    /**
     * @When I verify reporting results
     */
    public function iVerifyReportingResults()
    {
        $page = $this->getSession()->getPage();
        $dataTable = $this->findXpathElement("//table[@id='lg_datatable']//tbody//tr[last()]//td[1]");
        $text1 = $dataTable->getText();
        $page->find('xpath', "//*[@id='button_bar']/button[1]")->click();
        $this->iWillSee("Rows");
        $valueElem = $this->findXpathElement("//*[@id='SQLReportTable_info']");
        $value = $valueElem->getText();
        $split = explode(" ", $value);
        $text2 = $split[count($split) - 1];
        $result = strcmp($text1, $text2);
        if ($result !== 0) {
            throw new \Exception("Reporting results '$text1' and '$text2' do not match");
        }
    }

    /**
     * @When I check profile completion percent :status
     */
    public function profileCompletionPercent($status)
    {
        $this->iMouseOver('view-student-name');
        $element = $this->findXpathElement("//div[@class='completion-percentage']/span");
        $current_profile_percent = preg_replace('/%/', '', $element->getText());
        if ($status === 'increased') {
            if ($current_profile_percent < $this->profile_percent) {
                throw new \Exception("Not increased => Before: $this->profile_percent After: $current_profile_percent");
            }
        } elseif ($status === 'decreased') {
            if ($current_profile_percent > $this->profile_percent) {
                throw new \Exception("Not decreased => Before: $this->profile_percent After: $current_profile_percent");
            }
        }
        $this->profile_percent = null;
    }

    /**
     * @Given I locate the :file pdf on the page
     */
    public function iLocateThePdfOnThePage($file)
    {
        $pdfFile = $this->findXpathElement("//a[contains(@href,'$file') and contains(@href,'.pdf')]");
        $url = $pdfFile->getAttribute('href');
        $this->fileLocation = $this->getMinkParameter('base_url') . $url;
    }

    /**
     * @Then the total pdf page count should be :count
     */
    public function theTotalPageCountShouldBe($count)
    {
        $pdf = $this->parsePdf();
        $pages = $pdf->getPages();
        if (count($pages) !== (int) $count) {
            throw new \Exception('Expected total page count is ' . $count . ' but it contains ' . count($pages));
        }
    }

    /**
     * @Then /^the pdf should (not )?contain text "([^"]*)"$/
     */
    public function thePdfFileShouldContain($not, $string)
    {
        if (!($this->verifyPdfText($string) xor $not)) {
            throw new \Exception($string . ($not ? ' is' : ' is not') . ' found');
        }
    }

    /**
     * @Then /^page "([^"]*)" of the pdf should (not )?contain "([^"]*)"$/
     */
    public function pageShouldContain($number, $not, $string)
    {
        if (!($this->verifyPdfText($string, (int) $number) xor $not)) {
            throw new \Exception($string . ($not ? ' is' : ' is not') . ' found in page ' . $number);
        }
    }

    private function verifyPdfText($string, $pageNumber = null): bool
    {
        $isVerified = false;
        $pdf = $this->parsePdf();
        $unFormattedText = '';
        if ($pageNumber) {
            $pages = $pdf->getPages();
            --$pageNumber;
            if (isset($pages[$pageNumber])) {
                $unFormattedText = $pages[$pageNumber]->getText();
            }
        } else {
            $unFormattedText = $pdf->getText();
        }
        $formattedText = str_replace(["\n", "\r" , "\t", "\0"], '', $unFormattedText);
        if (strpos($formattedText, $string) !== false) {
            $isVerified = true;
        }
        return $isVerified;
    }

    private function parsePdf()
    {
        static $parsedFiles = [];
        static $pdfParser = null;
        if (!$pdfParser) {
            $pdfParser = new \Smalot\PdfParser\Parser();
        }
        if (!isset($parsedFiles[$this->fileLocation])) {
            $parsedFiles[$this->fileLocation] = $pdfParser->parseFile($this->fileLocation);
        }
        return $parsedFiles[$this->fileLocation];
    }

    /**
     * @When I verify ocr dates on :interface interface
     */
    public function iVerifyOcrDates($interface)
    {
        $page = $this->getSession()->getPage();
        if ($interface === 'manager') {
            $ocrDateLabel = $page->findAll('xpath',
                "//div[@class='sidebar-body'][1]/child::div/div[@class='OCRDatesLabel']");
            foreach ($ocrDateLabel as $key => $label) {
                $this->ocrDateLabels[$key] = $ocrDateLabel = $label->getText();
                $ocrDate = $page->find(
                    'xpath',
                    "//div[text()='$ocrDateLabel']/following::span[@class='date-widget']/input[1]"
                )->getAttribute('value');
                $amPm = $page->find(
                    'xpath',
                    "//div[text()='$ocrDateLabel']/following::div[@class='BodyText']"
                )->getText();
                $this->ocrDates[$key] = date("M d, Y,", strtotime($ocrDate)) . mb_substr($amPm, -9);
            }
        } elseif ($interface === 'student') {
            foreach ($this->ocrDateLabels as $key => $ocrDateLabel) {
                $this->iShouldSeeBefore($this->ocrDateLabels[$key], $this->ocrDates[$key]);
            }
            $this->ocrDateLabels = null;
            $this->ocrDates = null;
        }
    }

    /**
     * @When I open :kiosk kiosk url
     */
    public function iOpenKiosk($kiosk)
    {
        $page = $this->getSession()->getPage();
        $this->iAmLoggedOut();
        $this->iAmLoggedInAs('manager');
        $this->iNavigateTo("Students>Kiosks");
        $this->iSearchFor($kiosk);
        $this->iClickLink($kiosk);
        $kioskIdXpath = "//*[@name='dnf_class_values[kiosk][visible_id]']";
        $kioskId = $page->find('xpath', $kioskIdXpath);
        if ($kioskId) {
            $id = $kioskId->getAttribute('value');
            $kioskUrl = "/manager/kiosks/" . $id;
            $this->iAmLoggedOut();
            $this->iGoToInterface($kioskUrl);
            $this->iWillSee('Enter the 4 character access number for this kiosk');
        }
    }

    /**
     * @When /^I submit correct (\w+) credentials$/
     */
    public function iSubmitCorrectCredentials($interface)
    {
        $usernames = [
            'manager' => 'testmanager@iopexadmin.com',
            'manager_two' => 'testuser@iopexadmin.com', // limited user rights
            'manager_HOME' => 'home@iopexadmin.com', //full admin, but home page does not have Symport, App Cntr & stats
            'manager_FULL' => 'testuserFULL@iopexadmin.com', // full MSE admin not on MSE should have approval center
            'manager_hideMBA' => 'hidemba@manager.edu', //full admin with with "hide mba survey" user right "on"
            'manager_notsuperuser' => 'testmanagernotsuperuser@iopexadmin.com',
            'manager_MSE' => 'testuserMSE@iopexadmin.com', // full MSE admin, should have approval center
            'manager_shorty' => 'shorty@manager.edu', // full admin but with only shortcuts on home pages
            'manager_AE' => 'managerae@iopexadmin.com', // limited user rights, AE user right
            'employers' => 'employer1@test.edu',
            'employers_two' => 'employer2@test.edu',
            'employers_three' => 'employer3@test.edu',
            'employers_four' => 'employer4@test.edu',
            'employers_gen' => 'employergen@test.edu',
            'students_two' => 'student2@test.edu',
            'students_three' => 'student3@test.edu',
            'students_four' => 'student4@test.edu',
            'students_five' => 'student5@test.edu',
            'students_six' => 'student6@test.edu',
            'students' => 'student1@test.edu',
            'students_ALL' => 'allrights@example.edu',
            'students_alumni' => 'studentalumni@example.edu',
            'students_alumni2' => 'studentalum2@example.edu',
            'students_paul' => 'spaul@test.edu',
            'students_richard' => 'sanitysrichard@test.edu',
            'authenticatedStudentEval' => 'bill@eval.com',
            'authenticatedStudentEval2' => 'jane@eval.com',
            'MBA student' => 'mba10@demo.edu',
            'studentMBAapplicant' => 'mba7@demo.edu',
            'students_herald' => 'sherald@test.edu',
            'faculty' => 'faculty@test.edu',
            'recommender' => 'mike@symplicity.com'
        ];
        if (!isset($usernames[$interface])) {
            throw new \Exception("Unknown user/interface $interface");
        }
        $btn = $this->translate('Sign In');
        $password = ($interface === 'recommender' ? 'bomditok' : 'iopex@123');
        $this->login($usernames[$interface], $password, $btn);
    }

    /**
     * @When I open most recent Event Log
     */
    public function iOpenRecentEventLog()
    {
        $firstLogElem = $this->findXpathElement("//th[last()]/following::td[1]//a");
        $firstLog = $firstLogElem->getText();
        $currentDate = $this->translateDate(date(DATEFORMAT));
        if (stripos($firstLog, $currentDate) !== 0) {
            throw new \Exception("Recent Event Log not found - last one was from $firstLog instead of $currentDate.");
        }
        $this->clickXpath("//th[last()]/following::td[1]//a");
    }

    /**
     * @Then there should be a recent :label event log containing :text
     */
    public function thereShouldBeRecentLog(string $label, string $text)
    {
        $response = $this->fetchLastEventLogWithLabel($label, $text);
        if (!strpos($response, $text)) {
            // Sometimes it takes a few seconds for the log to update
            sleep(5);
            $response = $this->fetchLastEventLogWithLabel($label, $text);
            $this->checkRecentLogText($response, $label, $text);
        }
    }

    /**
     * @Then there should be a recent :label event log containing:
     */
    public function thereShouldBeRecentLogWith(string $label, TableNode $keywords)
    {
        $response = null;
        foreach ($keywords->getHash() as $row) {
            if (!$response) {
                $response = $this->fetchLastEventLogWithLabel($label, $row['text']);
            }
            $this->checkRecentLogText($response, $label, $row['text']);
        }
    }

    private function checkRecentLogText(string $response, string $label, string $text)
    {
        if (!strpos($response, $text)) {
            throw new \Exception(
                "Could not find a '$label' event log with '$text'. Last log was "
                . htmlentities(strip_tags($response), \ENT_NOQUOTES, 'UTF-8', false)
            );
        }
    }

    protected function fetchLastEventLogWithLabel(string $label, string $text = ''): string
    {
        $url = $this->getMinkParameter('base_url') . '/utils/getLastEventLog.php?label=' . urlencode($label);
        $translatedLabel = $this->translate($label);
        if ($translatedLabel && ($translatedLabel !== $label)) {
            $url .= '&translatedLabel=' . urlencode($translatedLabel);
        }
        if ($text) {
            $url .= '&text=' . urlencode($text);
        }
        $response = file_get_contents($url, false, stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]));
        require_once INCLUDE_BASE . '/crypt_lib.inc';
        return \sympAppDecrypt(APP_ENCRYPTION_KEY, $response, ['decode' => 'true']);
    }

    /**
     * @When /^I submit incorrect credentials$/
     */
    public function iSubmitIncorrectCredentials()
    {
        $this->login("tester" . time(), "bar", 'Sign In');
    }

    private function login($username, $password, $btn)
    {
        $this->fillField('username', $username);
        $this->fillField('password', $password);
        $this->pressButton($btn);
        $page = $this->getSession()->getPage();
        $popup = $page->find('xpath', '//div[contains(text(),"another location.")]');
        if ($popup) {
            try {
                $continueButton = $page->find('xpath', "//button[text()='Continue']")->getText();
                $this->iWillSee($continueButton);
                $this->clickXpath("//button[text()='Continue']");
            } catch (\Exception $e) {
                //probably it's not duplicate login alert
            }
        }
        $matches = [];
        preg_match('/(sanity.*)-csm\./', $this->getSession()->getCurrentUrl(), $matches);
        $match = $matches[1] ?? 'sanity';
        $match_lookup = [
            'sanity' => 'FULL-CSM',
            'sanity-law' => 'LAW-CSM',
            'sanity-modular' => 'MODULAR-CSM',
            'sanity-ae' => 'AE-CSM',
            'sanity-mse' => 'MSE-CSM',
            'sanity-cf' => 'CF-CSM',
            'sanity-enterprise' => 'ENTERPRISE-CSM',
            'sanity-interprise' => 'PT-CSM',
            'sanity-ar-interprise' => 'AR-CSM',
            'sanity-es-interprise' => 'ES-CSM',
            'sanity-stage' => 'FULL-CSM',
            'sanity-ae-stage' => 'AE-CSM',
            'sanity-mse-stage' => 'MSE-CSM',
            'sanity-modular-stage' => 'MODULAR-CSM',
            'sanity-law-stage' => 'LAW-CSM',
            'sanity-cf-stage' => 'CF-CSM',
            'sanity-enterprise-stage' => 'ENTERPRISE-CSM',
            'sanity-interprise-stage' => 'PT-CSM',
            'sanity-ar-interprise-stage' => 'AR-CSM',
            'sanity-es-interprise-stage' => 'ES-CSM'
        ];
        self::$tag = $match_lookup[$match];
        $this->iShouldSeeNoErrors();
        if ($this->getActiveInterface() === 'students') {
            $this->iCompleteOnBoardingProcess();
            $this->iShouldSeeNoErrors();
            $this->acceptPromoteAlert();
        } elseif ($this->getActiveInterface() === 'manager') {
            try {
                $element = $page->find('xpath', "//div[@role='dialog']");
                if ($element) {
                    $this->clickXpath('//div[@role="dialog"]//button[text()="Don\'t show again"]');
                }
            } catch (\Exception $e) {
                //probably no popup displayed
            }
        }
    }

    /**
     * @When I post to more schools in a new tab
     */
    public function iPostMoreSchools()
    {
        $this->iWillSee("Post once to multiple schools");
        $this->iClickLink('Post to More Schools');
        $this->iSwitchToBrowserTab(1);
        $this->iWillSee("proceed now");
        $this->iClickLink('proceed now');
        $this->iWillAcceptTheCookie();
        $this->iWillSee("Select Target Schools for Your Job");
    }

    public function iCompleteOnBoardingProcess()
    {
        try {
            $popup = $this->findXpathElement("//div[@class='welcome-content-container']", 10);
        } catch (\Exception $e) {
            // No onboarding, no problem
            $popup = null;
        }
        $page = $this->getSession()->getPage();
        if ($popup) {
            $getStarted = $this->translate('Get Started');
            $buttonDisabled = $page->find('xpath', "//*[@disabled and (@value='$getStarted' or @name='$getStarted' or @title='$getStarted' or text()='$getStarted' or text()=' $getStarted ' or @alt='$getStarted')]");
            if ($buttonDisabled) {
                $gdprCheckbox = $page->findField('gdprCheckbox');
                if ($gdprCheckbox) {
                    $this->checkOption('gdprCheckbox');
                }
            }
            if (self::$tag === "LAW-CSM") {
                $steps = [
                    "What type of jobs are you looking for?",
                    "Where would you like to work?",
                    "Which employer types interest you the most?",
                    "Which groups do you identify yourself with? (Optional)",
                    "Where have you worked?",
                    "You are all set!",
                    "Home"
                ];
            } elseif (in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                $steps = [
                    "What type of jobs are you looking for?",
                    "Where would you like to work?",
                    "Which industries are you interested in?",
                    "Which job functions are you interested in?",
                    "Where have you worked?",
                    "You are all set!",
                    "Home"
                ];
            } else {
                $steps = [
                    "What type of jobs are you looking for?",
                    "Where would you like to work?",
                    "Which industries are you interested in?",
                    "Which job functions are you interested in?",
                    "Which groups do you identify yourself with? (Optional)",
                    "Where have you worked?",
                    "You are all set!",
                    "Home"
                ];
            }
            $cnt = count($steps);
            for ($i = 0; $i < $cnt; $i++) {
                if ($i === 0) {
                    $btn = "Get Started";
                } elseif ($i === $cnt - 1) {
                    $btn = "Ok";
                } else {
                    $btn = "Next";
                }
                $this->iClickButtonOnModal($this->translate($btn));
                $this->iWillSee($steps[$i]);
            }
        }
        $modalContent = $page->find('xpath', "//div[@class='modal-content']//p[text()='" . $this->translate("Hey! We noticed you’ve been applying for jobs...") . "']");
        if ($modalContent) {
            $this->iClickButtonOnModal($this->translate("No, I am still applying"));
            $this->iWillSee("Thank you for your response");
        }
        $profileSetup = $page->find('xpath', "//div[@class='modal-content']/resume-parser-modal/div[2]/h3[text()='" . $this->translate("Quick Profile Setup") . "']");
        if ($profileSetup) {
            $this->iWillSee("Quick Profile Setup");
            $this->iClickButtonOnModal($this->translate("Not now"));
        }
    }

    public function acceptPromoteAlert()
    {
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', "//div[@id='optinmsg']");
        if ($element) {
            $this->switchToIFrame('optinmsg_inner');
            $promotePopup = $this->translate("No thanks");
            $this->clickXpath("//input[@value='$promotePopup']");
        }
    }

    /**
     * @Then /^I should stay logged out$/
     */
    public function iShouldStayLoggedOut(): bool
    {
        $page = $this->getSession()->getPage();
        $menu = $this->translate('My Account');
        return !$page->findField($menu);
    }

        /**
     * @Then /^I should be logged in to (\w+) interface$/
     */
    public function iShouldBeLoggedIn($interface)
    {
        switch ($interface) {
            case 'students':
            case 'employers':
            case 'faculty':
            case 'manager':
                $home = 'Home';
                break;
            case 'recommenders':
                $home = 'Welcome';
                break;
            default:
                throw new \Exception("Unknown interface $interface");
        }
        if (!substr_count($this->getSession()->getPage()->getText(), $home)) {
            throw new \Exception("$home not found! Login failed");
        }
    }

    public function iAmLoggedInAs($user)
    {
        //Added to use multiple user login in single interface
        $homeBtn = $this->translate('Home');
        $homeMenu = null;
        if ($this->role === $user) {
            $page = $this->getSession()->getPage();
            $signInBtn = $this->translate('Sign In');
            if (!$page->find('xpath', "//*[@value='$signInBtn']")) {
                $homePage = "//span[normalize-space(text())='$homeBtn']";
                $homeMenu = $page->find('xpath', $homePage);
            }
        }
        if ($homeMenu) {
            $this->iNavigateTo($homeBtn);
        } else {
            $this->role = $user;
            $interface = $this->getUserInterface($user);
            $this->iRetryUntilInterfaceLoaded($interface);
            $this->iSubmitCorrectCredentials($user);
        }
        $this->iWillSee('Home');
    }

    private function getUserInterface(string $user): string
    {
        $interface = strtolower(preg_replace('/.*(manager|employer|student|faculty|recommender).*/i', '$1', $user));
        if (in_array($interface, ['student', 'employer'])) {
            $interface .= 's';
        }
        return $interface;
    }

    public function getActiveInterface()
    {
        $currentUrl = $this->getSession()->getCurrentUrl();
        $splitUrl = explode('/', $currentUrl);
        return $splitUrl[3];
    }

    /**
     * @Given /^there are ([\s\w]+) records:$/
     */
    public function thereAreRecords($class, TableNode $records_table)
    {
        $this->records = $records_table->getHash();
    }

    /**
     * @Then /^I test all these filters$/
     */
    public function iTestAllFilters()
    {
        $page = $this->getSession()->getPage();
        $more_filters_button = $page->find('xpath', "//input[@id='toggle-hidden-filters'] | //input[@name='store_filters']/following::input[contains(@ng-reflect-ng-class,'[object Object]') or contains(@class,'input-button closed')]");
        if ($more_filters_button && ($more_filters_button->getValue() === $this->translate('More Filters'))) {
            $this->iClickLink([
                'link_object' => $more_filters_button,
                'link_label' => $this->translate('More Filters')
            ]);
            usleep(3700000);
        }
        foreach ($this->records as $record) {
            $alt = $record['alt'];
            $unexpected = $record['unexpected'];
            $filter = $this->translate($record['filter']);
            $pick = $this->translate($record['pick']);
            switch ($record['type']) {
                case "hierpick":
                    if ($alt === 'picklist') {
                        $field = $page->find('xpath', "//label[contains(text(),'$filter')]/following::select[contains(@id,'filters') or contains(@id,'sy_formfield_')][1]");
                        try {
                            $field->selectOption($pick);
                        } catch (Exception $e) {
                            $this->clickXpath("//option[normalize-space(text())='$pick')]");
                        }
                    } else {
                        $this->clickXpath("//div[contains(@id,'__widget')]/table[contains(@id,'hp_selection_')] | //label[contains(text(),'$filter')]/following::div[contains(@class,'picklist-field picklist-selected-text input-text ng-star-inserted')] | //div[contains(@id,'hp_input') and @title='" . $this->translate("Select") . "']");
                        $this->clickXpath("//div[@class='hp_key' and contains(.,'$pick')] | //label[@class='picklist-item-label' and contains(.,'$pick')]");
                    }
                    break;
                case "radio":
                    $this->iCheckRadioButton2($filter, $pick);
                    break;
                case "resume":
                case "text":
                    $this->fillField($alt, $pick);
                    break;
                default:
                    $field = $page->findField($filter);
                    if (null === $field) {
                        throw new \Exception("Filter '$filter' was not found.");
                    }
                    $field->selectOption($pick);
            }
            $source = $page->find('xpath', "//label[normalize-space(text())='Source']/following::select[contains(@id,'sy_formfield_source_')]");
            if ($source) {
                $source->selectOption("CSM");
            }
            $this->useButton('apply search');
            $this->iWillSee($record['expectation']);
            if (!empty($unexpected)) {
                $this->iShouldAlsoNotSee($unexpected);
            }
            $this->iClickToButton('Clear Filters');
        }
        $fewer_filters_button = $page->findById('toggle-hidden-filters');
        if ($fewer_filters_button && ($fewer_filters_button->getValue() === $this->translate('Fewer Filters'))) {
            $this->iClickLink([
                'link_object' => $fewer_filters_button,
                'link_label' => $this->translate('Fewer Filters')
            ]);
            usleep(3700000);
        }
    }

    /**
     * @Then /^I click "([^"]*)" link with correct tag "([^"]*)" I should see "([^"]*)"$/
     */
    public function iTestHomeLinks($label, $sites_skipped, $keyword)
    {
        // check if current site has target link on it. If not, skip this row
        if (!empty($sites_skipped)) {
            $skip_set = explode(' ', $sites_skipped);
            if (in_array(self::$tag, $skip_set)) {
                return;
            }
        }
        $page = $this->getSession()->getPage();
        $label = $this->translate($label);
        $link = $page->findLink($label);
        if (empty($link)) {
            throw new \Exception("Link '$label' was not found on Home Page.");
        }
        if ($link->isVisible() === false) {
            throw new \Exception("Link '$label' is not visible on Home Page.");
        }
        $this->iClickLink(['link_object' => $link, 'link_label' => $label]);

        if (!$this->textSearchWithoutSpin($keyword)) {
            $this->iClickLink('Home');
            throw new \Exception("After we clicked on $label, '$keyword' text not found.");
        }
    }

    /**
     * @Then /^I go through each (\w+) record$/
     */
    public function iGoThroughRecords($class)
    {
        $page = $this->getSession()->getPage();
        if ($class === 'section') {
            $tab = 'section';
            $keyword = 'content';
            $labels = array_column($this->records, 'section');
            $label_counts = array_count_values($labels);
            $click_history_of_tabs_with_same_label = [];
        } elseif ($class === 'home_page_link') {
            $tab = 'link';
            $keyword = 'keyword';
            $bounce_back_link = 'Home';
        } elseif ($class === 'system_setting') {
            $tab = 'tab';
            $keyword = 'keyword';
        } else {
            throw new \Exception("Unknown record class $class");
        }
        foreach ($this->records as $record) {
            //the following code may give "Element is not currently visible and may not be manipulated" error,it means the link could be find, but it is hidden by css attribute, maybe we can handle it by expanding all parent tabs in the first place
            $label = $record[$tab];
            if (self::$tag && !empty($record['sites_skipped'])) {
                $sites_skipped = explode(' ', $record['sites_skipped']);
                if (in_array(self::$tag, $sites_skipped)) {
                    continue;
                }
            }
            $search_for_keyword = $record[$keyword];
            $search_for_keyword = $this->translate($search_for_keyword);
            $loading_lag_expected = 0;
            $loading_keywords = ['Delimiter'];
            if (in_array($search_for_keyword, $loading_keywords)) {
                $loading_lag_expected = 1;
            }
            $label = $this->translate($label);
            $link = $page->findLink($label);
            //this is for when we have labels with the same text
            $subtabs_with_same_label = $page->findAll('xpath', "//span[contains(.,'" . $label . "')]");
            if (empty($link)) {
                throw new \Exception("Tab '$label' was not found.");
            }
            if ($class !== 'system_setting' && $link->isVisible() === false) {
                //findLink() returns the first link it sees, no matter it's visible or not, so we need to handle it by skipping the invisible links
                foreach ($subtabs_with_same_label as $subtab) {
                    if ($subtab->isVisible() === true) {
                        $this->iClickLink(['link_object' => $subtab, 'link_label' => $label]);
                        $visible = 1;
                    }
                }
                if (empty($visible)) {
                    throw new \Exception("Tab '$label' is not visible.");
                }
            } else {
                //the ultimate solution for clicking the correct tab when two or more tabs got the same label is, add another column to $this->record table called level, so we can tell if a tab is on parent level or child level, then lock it by the parent css/xpath link
                if (isset($click_history_of_tabs_with_same_label) && in_array($label, $click_history_of_tabs_with_same_label)) {
                    //subtab confirmed
                    $sub_link = $page->find('xpath', "//span[@class='tab_text' and contains(.,'" . $label . "')]");
                    if ($sub_link === null) {
                        $this->iClickLink(['link_object' => $link, 'link_label' => $label]);
                    } else {
                        $this->iClickLink(['link_object' => $sub_link, 'link_label' => $label]);
                    }
                } elseif ($class === 'system_setting') {
                    $tab_link = $page->find('xpath', "//span[@class='tab_text' and contains(.,'" . $label . "')]");
                    $tab_link_has_break = $page->find('xpath',
                        "//*[@id='ui_module_titlebar' and contains(.,'" . $label . "')]");
                    $system_setting_links = $page->findAll('named', ['link', $label]);
                    if (!empty($tab_link)) {
                        $this->iClickLink(['link_object' => $tab_link, 'link_label' => $label]);
                    } elseif (!empty($tab_link_has_break)) {
                        $this->iClickLink(['link_object' => $tab_link_has_break, 'link_label' => $label]);
                    } elseif ($link->isVisible() === true && count($system_setting_links) === 1) {
                        $this->iClickLink(['link_object' => $link, 'link_label' => $label]);
                    } elseif (count($system_setting_links)) {
                        $this->iClickLink(['link_object' => $system_setting_links[1], 'link_label' => $label]);
                    } else {
                        throw new \Exception("When we are testing system settings, tab '$label' is not found.");
                    }
                } else {
                    $this->iClickLink(['link_object' => $link, 'link_label' => $label]);
                    if (isset($label_counts) && $label_counts[$label] > 1) {
                        $click_history_of_tabs_with_same_label[] = $label;
                    }
                }
            }
            if ($loading_lag_expected) {
                $this->getSession()->wait(5000, '(0 === jQuery.active');
            }
            if (!$this->textSearchWithoutSpin($search_for_keyword)) {
                throw new \Exception("When we are on section $label, '$search_for_keyword' text not found.");
            }
            if (isset($bounce_back_link)) {
                $this->iClickLink($bounce_back_link);
            }
            //The following string is a hardcoded warning if ONESTOP is on, which need to be skipped, otherwise it will break the test.
            if ($class !== 'system_setting'
                && !$this->textSearchWithoutSpin("Multi School Postings will be auto")
                && !($label === 'Mailwiz' && $this->textSearchWithoutSpin("No items were selected"))
            ) {
                $this->iShouldSeeNoErrors();
            }
        }
    }

    private function textSearchWithoutSpin($text)
    {
        $page = $this->getSession()->getPage();
        return substr_count($page->getText(), $text);
    }

    /**
     * @When /^I test all sorts$/
     */
    public function iTestAllSorts()
    {
        static $translatedValue = [
            'AR-CSM' => [
                'Apr 15, 2018, 10:00 AM' => '١٥ أبريل ٢٠١٨ م ١٠:٠٠ ص',
                'Apr 11, 2018, 6:00 PM' => '١١ أبريل ٢٠١٨ م ٦:٠٠ م'
            ],
            'ES-CSM' => [
                'Apr 15, 2018, 10:00 AM' => '15 de abr. de 2018 10:00',
                'Apr 11, 2018, 6:00 PM' => '11 de abr. de 2018 06:00'
            ],
            'PT-CSM' => [
                'Apr 15, 2018, 10:00 AM' => '15 de abr. de 2018 10:00',
                'Apr 11, 2018, 6:00 PM' => '11 de abr. de 2018 06:00'
            ]
        ];
        foreach ($this->records as $record) {
            if ($record['default'] !== 'yes') {
                if (in_array($record['column'], ['Approvers', 'Assigned Approval Workflow'])) {
                    $this->iClickToButton($this->translate($record['column']));
                } else {
                    $this->clickXpath("//th//following::*[text()='" . $this->translate($record['column']) . "']");
                }
            }
            foreach (['sooner', 'later'] as $key) {
                $record[$key] = $translatedValue[self::$tag][$record[$key]] ?? $this->translate($record[$key]);
            }
            $this->iShouldSeeBefore($record['sooner'], $record['later']);
        }
    }

    /**
     * @When /^I test sorts$/
     */
    public function iTestSorts()
    {
        foreach ($this->records as $record) {
            if ($record['default'] !== 'yes') {
                $activeInterface = $this->getActiveInterface();
                if ($activeInterface === 'students') {
                    $sort = $this->translate('Sort by');
                    $label = $this->getSession()->getPage()->find('xpath', "//*[@aria-label='$sort']");
                    if ($label) {
                        $label->selectOption($record['column']);
                    }
                } else {
                    $sort = $this->translate('Sort By') . ':';
                    $this->selectOption($this->translate($record['column']), $sort);
                }
            }
            $sooner = $this->translate($record['sooner']);
            $later = $this->translate($record['later']);
            $this->iShouldSeeBefore($sooner, $later);
        }
    }

    /**
     * @When /^I open "([^"]*)" section$/
     */
    public function iOpenSection($section)
    {
        //we either save the tab relation here, or map it to the outline
        $parent_link = '';
        if (in_array($section, ['e-Newsletter', 'Announcement'])) {
            $parent_link = 'Communications';
        } elseif (in_array($section, [
            'Experiential Learning',
            'Mid-Term Self Evaluation',
            'Self Evaluation',
            'Program Evaluation',
            'Employer Evaluation',
            'Work Term'
        ])) {
            $parent_link = 'Exp. Learning';
        } elseif (in_array($section,
            ['Positions', 'Schedules', 'Interviews', 'Sessions', 'Rooms', 'Holidays', 'Archives'])) {
            $parent_link = 'OCR';
        } elseif (in_array($section, ['Career Fairs', 'Info Sessions', 'Workshops', 'Locations'])) {
            $parent_link = 'Events';
        } elseif (in_array($section, [
            'Emails',
            'Users/Groups',
            'System Settings',
            'Picklists',
            'Links',
            'Import Data',
            'PDF Queue',
            'Email Queue',
            'Form Builder',
            'List Builder',
            'Event Log',
            'Help/Hints',
            'Usage Stats',
            'Sponsors'
        ])) {
            $parent_link = 'Tools';
        }
        if ($parent_link) {
            $this->iClickLink($parent_link);
        }
        $this->iClickLink($section);
    }

    /**
     * @When I open :tab tab/
     */
    public function iOpenTab($tab)
    {
        $this->iWillSeeLoadingIndicator();
        $this->iScrollBackToTop();
        $activeInterface = $this->getActiveInterface();
        $tabLabel = $this->translate($tab);
        if ($activeInterface === 'employers' && $tab === 'Information Sessions') {
            $tabLabel = ucwords($tabLabel);
        }
        if (in_array($activeInterface, ['employers', 'faculty', 'students'])) {
            try {
                $this->clickXpath("//a[(@role='tab' and (@title=\"$tabLabel\" or (@translate and text()=\"$tabLabel\") or normalize-space(text())=\"$tabLabel\"))]");  
            } catch (\Exception $e) {
                $this->iClickLink($tabLabel, ".scroll_tabs_container a:contains('$tabLabel')");
            }
        } else {
            try {
                $this->iClickLink($tabLabel, ".tabs a:contains('$tabLabel')");
            } catch (\Exception $e) {
                $this->iClickLink($tabLabel, ".tabs a[title='$tabLabel']");
            }
        }
    }

    /**
     * @Then I open :tab tab and see :text
     */
    public function iOpenTabAndSee($tab, $text)
    {
        $this->iOpenTab($tab);
        $this->iWillSee($text);
    }

    /**
     * @Then I switch back to :tab tab
     */
    public function iSwitchBackToTab($tab)
    {
        $this->iOpenTab($tab);
    }

    /**
     * @When I open :subtab subtab
     */
    public function iOpenSubTab($subtab)
    {
        $subtab = $this->translate($subtab);
        $this->clickXpath("//div[@class='sub sub_1']//td/a[@title='$subtab']");
    }

    /**
     * @When I open :subtab sub-subtab
     */
    public function iOpenSubSubTab($subtab)
    {
        $subtab = $this->translate($subtab);
        $this->clickXpath("//div[@class='sub sub_2']//td/a[@title='$subtab']");
    }

    /**
     * @Then /^I should see no errors$/
     */
    public function iShouldSeeNoErrors()
    {
        $page = $this->getSession()->getPage();
        if (($error = $page->find('css', '.alert-error'))
            || ($error = $page->find('css', '.alert-danger'))
            || ($error = $page->find('css', '.xdebug-error'))
            || ($error = $page->find('css', '.alert.error'))
            || ($error = $page->find('css', '.errors'))
        ) {
            throw new \Exception($error->getText());
        }
        $jsErrors = $this->getJsErrors();
        if ($jsErrors) {
            throw new \Exception($jsErrors);
        }
    }

    public function iClickLink($link, $css = '')
    {
        $this->acceptConfirmation();
        if (is_string($link)) {
            $link = $this->translate($link);
            return parent::iClickLink($link, $css);
        }
        $link_object = $link['link_object'];
        if (is_object($link_object)) {
            $this->spin(static function () use ($link_object) {
                $link_object->click();
                return true;
            });
            return $link_object;
        }
        throw new \Exception("We cannot find '" . $link['link_label'] . "' on the current page.");
    }

    protected function getTabBarStyle(): string
    {
        $interface = $this->getActiveInterface();
        return ($interface === "manager") ? "div.titlebar" : "div.flickity-slider";
    }

    /**
     * @When /^I click "([^"]*)" on kiosk keyboard$/
     */
    public function iClickKey($key)
    {
        $page = $this->getSession()->getPage();
        if (!$key) {
            $digit_key_value = 11;
        } else {
            $digit_key_value = ((int) $key) + 1;
        }
        $class = "kbkey keynum" . $digit_key_value;
        $link = $page->find('xpath', "//*[@class='" . $class . "']");
        //we merged kiosk return click action into this function just for now
        if ($key === 'return') {
            $link = $page->find('xpath', "//*[@class='kbkey_wide keynum41']");
            $link->mouseOver();
        } elseif ($key === 'Walk-In') {
            $link = $page->find('xpath', '//*[@id="lobby_picks"]/div/div');
            $link->mouseOver();
        }
        $this->iClickLink(['link_object' => $link, 'link_label' => $key]);
    }

    /**
     * @When I fill :nums on kiosk keyboard
     */
    public function iFillOnKioskKeyboard($nums)
    {
        if (!empty($nums)) {
            $limit = str_split($nums);
            $array = 0;
            while (isset($limit[$array]) && $array < $limit) {
                $this->iClickKey($limit[$array]);
                ++$array;
            }
        }
    }

    /**
     * @When /^I click "([^"]*)" subtab$/
     */
    public function iClickSubtab($subtab)
    {
        $subtab = $this->translate($subtab);
        $page = $this->getSession()->getPage();
        $link = $page->find('xpath', "//*[@id='ui_module_tabs']/tbody/tr/td[6]/a/span");
        //we merged kiosk return click action into this function just for now
        $this->iClickLink(['link_object' => $link, 'link_label' => $subtab]);
    }

    /**
     * @When /^I check the "([^"]*)" radio button$/
     */
    public function iCheckRadioButton($radioLabel)
    {
        $radioLabel = $this->translate($radioLabel);
        $radioButton = $this->getSession()->getPage()->findField($radioLabel);
        if (null === $radioButton) {
            throw new \Exception("Cannot find radio button => $radioLabel");
        }
        $this->getSession()->getDriver()->click($radioButton->getXPath());
    }

    /**
     * @When I find checkbox :record is checked
     */
    public function iCheckCheckboxChecked($record)
    {
        $page = $this->getSession()->getPage();
        $record = $this->translate($record);
        if (strpos($record, ':')) {
            [$record1, $record2] = explode(':', $record);
            $newrec2 = $this->translate(trim($record2));
            $element = $page->find('xpath', "//*[@data-text-value='" . $record1 . ": " . $newrec2 . "' and @checked]");
        } elseif (strpos($record, '_of_')) {
            [$record1, $record2] = explode('_of_', $record);
            $newrec2 = $this->translate($record2);
            $element = $page->find('xpath', "//*[@alt='" . $record1 . " " . $newrec2 . "' and @checked]");
            if (!$element) {
                $element = $this->ngIsChecked($record1, $newrec2);
            }
        } else {
            $element = $page->find('xpath', "//*[@title='$record' or @aria-label='$record' or @value='$record' or @data-text-value='$record' and @checked]");
        }
        if (!$element) {
            throw new \Exception("$record is not checked!");
        }
    }

    /**
     * @When I find checkbox :record is unchecked
     */
    public function iCheckCheckboxUnchecked($record)
    {
        $page = $this->getSession()->getPage();
        if (strpos($record, '_of_')) {
            [$field, $value] = explode('_of_', $record);
            $element = $this->ngIsChecked($field, $this->translate($value));
        } else {
            $element = $page->find('xpath', "//*[@checked and (@title='$record' or @alt='$record' or @aria-label='$record' or @data-text-value='$record')]");
        }
        if ($element) {
            throw new \Exception("$record is checked!");
        }
    }

    /**
     * @When /^I search for "([^"]*)"$/
     */
    public function iSearchFor($keywords)
    {
        $this->fillField($this->translate('Keywords'), $keywords);
        $this->useButton('apply search');
    }

    private function ngIsChecked($field, $value)
    {
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', "//div[normalize-space(text())='$field']");
        if ($element && in_array($value, ['Yes', 'No'])) {
            $id = $element->getAttribute('id');
            $position = ($value === 'Yes') ? '-1' : '-0';
            return $this->getSession()->evaluateScript("document.getElementById('$id$position').checked");
        }
        throw new \Exception($field . ' not found');
    }

    /**
     * @When /^I click "([^"]*)" icon$/
     */
    public function iClickIcon($icon)
    {
        $this->iWillSeeLoadingIndicator();
        $icon = $this->translate($icon);
        $page = $this->getSession()->getPage();
        $link = $page->findButton($icon);
        $this->iClickLink(['link_object' => $link, 'link_label' => $icon]);
    }

    /**
     * @When /^I select (\w+) "([^"]*)" for (\w+)$/
     */
    public function iSelectEmployer($field_type, $name, $object)
    {
        if ($field_type === 'employer') {
            if ($object === 'contact') {
                $fieldId = 'autocomplete_input_dnf_class_values_contact__employer_';
            } elseif ($object === 'job') {
                $fieldId = 'autocomplete_input_dnf_class_values_job__job_emp_';
            } elseif ($object === 'session') {
                $fieldId = 'autocomplete_input_dnf_class_values_presentation__employer_';
            } else {
                throw new \Exception("Unexpected field object $object");
            }
            $click_on_id = 'ac_pick_id_0_c91c349be782426e1c3cda64db1aae2d';
        } elseif ($field_type === 'student') {
            $fieldId = 'autocomplete_input_dnf_class_values_career_counseling__student_';
            $click_on_id = 'ac_pick_id_0_6480a0616febfcd688ef237ee9a6eb87';
        } else {
            throw new \Exception("Unexpected field type $field_type");
        }
        $this->fillAutoPopulateFields($fieldId, $name, $click_on_id);
    }

    private function fillAutoPopulateFields($field, $value, $click_on_id)
    {
        $element = $this->getSession()->getPage()->findById($field);
        $this->iClickLink(['link_object' => $element, 'link_label' => $field]);
        $this->fillField($field, $value);
        $this->iClickLink(['link_object' => $element, 'link_label' => $field]);
        usleep(2100000);
        $this->iMouseOver($click_on_id);
        //we can only locate the following element by using findById,because it's not a link
        $element = $this->getSession()->getPage()->findById($click_on_id);
        $this->iClickLink(['link_object' => $element, 'link_label' => $click_on_id]);
        usleep(5300000);
    }

    /**
     * @Then /^I take care of the "([^"]*)" field$/
     */
    public function iTakeCareOfField($field)
    {
        if (strpos($field, 'End')) {
            $date = date('Y-m-d', strtotime(date('Y-m-d') . ' + 365 day'));
        } elseif (strpos($field, 'Expiration')) {
            $date = date('Y-m-d', strtotime(date('Y-m-d') . ' + 30 day'));
        } else {
            $date = date('Y-m-d');
        }
        $this->fillField($field, $date);
    }

    /**
     * @When /^I pick "([^"]*)" as major$/
     */
    public function iPickMajor($major)
    {
        $major = $this->translate($major);
        $this->clickXpath("//div[contains(@id,'major____widget')]/div[@class='scrollable_readonly_ms']");
        $this->clickXpath("//div[contains(@id,'hp_input')]");
        $this->clickXpath("//div[contains(@class,'hp_key') and contains(.,'$major')]");
    }

    /**
     * @Then I click :arg1 next to :arg2
     */
    public function iClickNextTo($arg1, $arg2)
    {
        $this->clickXpath("//div[text()='$arg2']//following::button[contains(text(),'$arg1')] | //a[contains(@aria-label, '$arg2')]/label[text()='$arg1'] | //h2[text()='$arg2']//following::button[contains(@class,'cover-edit bio-upload-button')]//span[text()='$arg1'] | //h1[contains(text(), '$arg2')]/following::div[contains(@class,'align-self-end button-group ng-star-inserted')]//span[text()='" . $this->translate($arg1) . "'] | //*[contains(text(), '$arg2')]/ancestor::div[contains(@class,'header-title buttons-beside-title')]/following::span[text()='" . $this->translate($arg1) . "'] | //div[contains(text(),'" . $this->translate($arg2) . "')]//following::td/a/div[contains(text(),'" . $this->translate($arg1) . "')] | //*[text()='$arg2']/following::input[@value='$arg1'][1]");
    }

    /**
     * @When I scroll back to top
     */
    public function iScrollBackToTop()
    {
        $this->getSession()->executeScript("(function(){window.scrollTo(0,0);})();");
    }

    /**
     * @When /^I mouse over the element "([^"]*)"$/
     */
    public function iMouseOver($label)
    {
        $label = $this->translate($label);
        $element = $this->getSession()->getPage()->findById($label);
        if ($element === null) {
            throw new \Exception("$label not found! We got nothing to mouse over!");
        }
        $element->mouseOver();
    }

    /**
     * @When I mouse over on :label section
     * @When I mouse over on :label button
     */
    public function iMouseOverSection($label)
    {
        $this->spin(function () use ($label) {
            $label = $this->translate($label);
            $element = $this->getSession()->getPage()->find('xpath', "//*[text()='$label'] | //a[@class='$label']");
            if ($element === null) {
                throw new \Exception("$label not found! We got nothing to mouse over!");
            }
            $element->mouseOver();
            return true;
        });
    }

    /**
     * @When I mouse over :section section and click :event event
     */
    public function iMouseOverAndClick($section, $event)
    {
        $section = $this->translate($section);
        $event = $this->translate($event);
        $element = $this->getSession()->getPage()->find('xpath', "//h2[text()='$section']/following::ul[1]/li[last()]");
        if ($element) {
            $element->mouseOver();
            $this->iWait(2000);
            $eventLink = $this->getSession()->getPage()->find('xpath',
                "//h2[text()='$section']/following::a[text()='$event']");
            if ($eventLink) {
                $eventLink->mouseOver();
                $this->iWait(2000);
                $eventLink->click();
            } else {
                throw new \Exception("$event not found! We got nothing to mouse over!");
            }
        } else {
            throw new \Exception("$section not found! We got nothing to mouse over!");
        }
    }

    /**
     * @When I mouse over :id and see :text in popup
     */
    public function iMouseOverAndSee($id, $text)
    {
        //will add additional cases based on other modules in future
        if ($id === "Appointment Notes" || $id === "Mentee Note") {
            $id = "large_column_abbr_0";
        }
        $this->iMouseOver($id);
        $popup = $this->findXpathElement("//div[contains(@class,'status_')]/div[@id='large_column_full_0']");
        $popupText = $popup->getText();
        $result = strcmp($text, $popupText);
        if ($result !== 0) {
            throw new \Exception("Text '$text' doesn't match with the popup");
        }
    }

    /**
     * @When I mouse over the :section sidebar and click the :button button
     */
    public function iMouseOverTheSidebarAndClickTheButton($section, $button)
    {
        $this->iMouseOver('view-sidebar-links');
        $this->iClickToButton($button . '_of_' . $section);
    }

    public function ultimateSearch($target_name, $target_xpath, $helpler_xpath)
    {
        $target_name = $this->translate($target_name);
        $page = $this->getSession()->getPage();
        $label_elements = $page->findAll('xpath', $helpler_xpath);
        foreach ($label_elements as $key => $label) {
            if ($label->getText() === $target_name) {
                $matchedKeyPosition = $key;
                break;
            }
        }
        if (isset($matchedKeyPosition)) {
            $target_elements = $page->findAll('xpath', $target_xpath);
            $target = $target_elements[$matchedKeyPosition];
            if (!empty($target) && is_object($target)) {
                return $target;
            }
            throw new \Exception('Target element ' . $target_name . ' was not found');
        }
        throw new \Exception('Helpler element ' . $target_name . ' was not found');
    }

    /**
     * @Then /^I click the view icon of "([^"]*)"$/
     */
    public function iClickViewIcon($label)
    {
        $label = $this->translate($label);
        $element = $this->ultimateSearch($label, "//img[@src='/images/icon_view.gif' and @alt='View']",
            "//td[@class='cspList_main lst-cl' and @headers='lh_title']");
        $this->iClickLink(['link_object' => $element, 'link_label' => $label]);
    }

    /**
     * @Then I delete :record record
     */

    public function iDeleteRecord($record)
    {
        $session = $this->getSession();
        $page = $session->getPage();

        // we disable delete confirmation alert here since PhantomJS cannot handle it,
        // in the future we should improve the workflow to make sure the alert box got triggered.
        $session->executeScript("window.confirmDel = function (whatString) {return true;}");

        $deleteButton = $page->find('xpath',
            ".//input[@value='" . $this->translate("Delete") . "' or @value='" . $this->translate("delete") . "']");
        if (!$deleteButton) {
            $deleteButton = $page->find('xpath',
                ".//input[@value='" . $this->translate("Purge") . "' or @value='" . $this->translate("purge") . "']");
        }
        if ($deleteButton) {
            $deleteButton->click();
            usleep(3500000);
        } else {
            $this->iPurgeRecord('Delete');
        }
    }

    /**
     * @Then I delete :project project
     */
    public function iDeleteProject($project)
    {
        $page = $this->getSession()->getPage();
        $projectExists = $page->find('css', "div[id^=view-projects]");
        $translateDelete = $this->translate('Delete');
        if ($projectExists) {
            $this->iWillSee($project);
            $editXpath = "//p[text()='$project']/ancestor::div[@class='project-content flex-gt-xs-100']//md-icon[text()='edit']";
            $this->focusAndClick($editXpath);
            $this->iWillSeeButton(null, 'Delete');
            $this->spin(function () use ($page, $translateDelete, $projectExists) {
                $deleteXpath = "//div[contains(@class,'form-action layout-wrap')]//button[text()='$translateDelete']";
                $deleteButton = $page->find('xpath', $deleteXpath);
                if ($deleteButton) {
                    $this->iMouseOverSection($translateDelete);
                    $deleteButton->focus();
                    $deleteButton->click();
                    $this->iShouldSeeInDialogbox(null, 'Discard this entry');
                    $this->iWillSee('Are you sure you want to delete this item?');
                    $this->iWillSeeButton(null, 'Discard');
                    $this->iClickButtonInDialogbox('Discard');
                    $this->iWillSee('Successfully Deleted');
                }
                return true;
            });
        } else {
            throw new \Exception('Project Not exists');
        }
    }

    /**
     * @When I purge the record
     */
    public function iPurgeRecord($action = 'Purge')
    {
        $session = $this->getSession();

        //we disable delete confirmation alert here since PhantomJS cannot handle it
        //in the future we should improve the workflow to make sure the alert box got triggered.
        $session->executeScript("window.confirmDel = function confirmDel(whatString, key, sesskey) {location = '?'+key+'&'+sesskey;}");
        $action = $this->translate($action);
        $batchOptions = $this->translate('Batch Options');
        $session->getPage()->checkField("_csp_list_checkbox[]");
        $this->clickXpath("//div[@title='$batchOptions' and contains(.,'$batchOptions')]");
        $lcAction = mb_strtolower($action);
        $xpath = "//div[contains(@class,'hp_key') and (contains(.,'$action') or contains(.,'$lcAction'))]";
        $this->clickXpath($xpath);
        $this->iWillSeeLoadingIndicator();
    }

    /**
     * @Then /^the badge file should be downloaded$/
     */
    public function assertFileDownloaded()
    {
        $download_path = __DIR__ . '/download/';
        $badge = scandir($download_path);
        if (!empty($badge)) {
            throw new \Exception(implode(':', $badge));
        }
    }

    /**
     * @When I select :label with :value
     */
    public function iSelectWith($label, $value, $optional = '')
    {
        $activeInterface = $this->getActiveInterface();
        $this->getSession()->executeScript('window.scrollTo(0,100);');
        $translatedLabel = $this->translate($label);
        $Loc1 = $this->translate('Location');
        $Loc2 = $this->translate('Location(s)');
        $Loc3 = $this->translate('Job Location(s)');
        if ($label === 'Location' && $activeInterface !== 'students') {
            if ($optional === 'events') {
                $xpath = "//*[text()='$label']/following::input[1]";
            } else {
                $xpath = "//*[@class='autocomplete-widget ui-front' or @class='autocomplete-widget' or @class='input-clear-group']/preceding::label[1][contains(text(),'$Loc1') or text()='$Loc2' or text()='$Loc3']";
            }
        } else {
            $xpath = "//*[(contains(@id,'autocomplete_input') or contains(@id,'_value') or contains(@id,'input-') or contains(@id,'discovery-location') or contains(@id,'" . mb_strtolower($label) . "')) and (@title='$label' or @js_required_field='$label' or contains(@placeholder,'$label') or @title='$translatedLabel' or @js_required_field='$translatedLabel' or contains(@placeholder,'$translatedLabel') or @aria-labelledby='dnf_class_values$label' or @aria-label='$label')]";
        }
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', $xpath);
        if ($element) {
            if ($label === 'Location' && $activeInterface !== 'students' && $optional !== 'events') {
                $xpath = "//*[@class='autocomplete-widget ui-front' or @class='autocomplete-widget' or @class='input-clear-group']/child::input[contains(@id,'_location')]";
                $element = $page->find('xpath', $xpath);
            }
            if ($optional === 'events') {
                $id = $element->getAttribute('placeholder');
            } else {
                $id = $element->getAttribute('id');
            }
            if ($label === 'Desired Skills' || $label === 'Location') {
                if ($value === 'custom') {
                    $value = "skill_" . time();
                    $this->customSkill = $value;
                }
                if ($label === 'Location' && $activeInterface === 'students') {
                    $xpath = "//*[contains(@id,'discovery-location')]/child::div/input | //*[contains(@id,'jobs-location-input')]";
                    $element = $page->find('xpath', $xpath);
                    $id = $element->getAttribute('placeholder');
                    $this->clickXpath($xpath);
                }
                $translatedValue = $value;
                if ($value === 'United States') {
                    $translatedValue = $this->translateCountry($value, $this->locale);
                }
                $this->fillField($id, '');
                if (($label === 'Location' && $activeInterface === 'students') || ($id === "Search and Select Locations")) {
                    $this->fillField($id, $value);
                } else {
                    $this->postValueToXpath("//*[@id='$id']", $value);
                }
                if ($activeInterface !== 'students' && $optional !== 'events') {
                    $page->findById($id)->keyPress(13);
                }
                if ($id !== "Search and Select Locations") {
                    if ($label === 'Location') {
                        if ($value !== 'United States' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                            if ($activeInterface === 'manager' || $activeInterface === 'employers') {
                                $xpath = "//li[@class='ui-menu-item']/div[1]";
                            } elseif ($activeInterface === 'students') {
                                $xpath = "//div[@ng-if='!matchClass'][1]";
                            }
                            $translatedElem = $this->findXpathElement($xpath);
                            $translatedValue = $translatedElem->getText();
                        }
                        try {
                            $this->clickXpath("//*[text()='$translatedValue']");
                        } catch (\Exception $e) {
                            if (!$translatedValue) {
                                $page = $this->getSession()->getPage();
                                $this->spin(static function ($context) use ($page, $xpath) {
                                    $translatedValue = $page->find('xpath', "//li[@class='ui-menu-item']/div[1]")->getText();
                                    if ($translatedValue) {
                                        $page->find('xpath', "//*[text()='$translatedValue']")->click();
                                        return true;
                                    }
                                });
                            }
                        }
                    } else {
                        $this->clickXpath("//*[text()='$label' or text()='$translatedLabel']");
                    }
                }
            } elseif ($label === 'Search Skills Here' || $label === 'Search Clubs/Organizations Here') {
                $this->fillField($id, '');
                $this->postValueToXpath("//*[@id='$id']", $value);
                $this->clickXpath("//span[text()='$value']");
            } else {
                $this->fillField($id, '');
                $this->postValueToXpath("//*[@id='$id']", $value);
                $nameParts = array_reverse(explode(' ', $value));
                $stuName = array_shift($nameParts) . ', ';
                $stuName .= implode(' ', $nameParts);
                $this->clickXpath("//div[starts-with(@id,'ac_pick_id_') and (contains(.,'$stuName') or contains(.,'$value'))]");
            }
        } else {
            $xpath = "//div[@class='list-item-body' and contains(.,'$label')]//select";
            $element = $page->find('xpath', $xpath);
            if ($element) {
                $element->selectOption($value);
            } else {
                $element = $page->find('xpath', "//select[@ng-model=\"$label\"]");
                if ($element) {
                    $element->selectOption($value);
                } else {
                    $label = $this->translate($label);
                    $element = $page->find('xpath', "//label[normalize-space()='$label']");
                    if (!$element) {
                        $element = $page->find('xpath', "//label[contains(.,'$label')]");
                    }
                    if ($element) {
                        $element = $element->getAttribute('for');
                        if ($label !== 'Desired Skills') {
                            $page->find('xpath', "//input[contains(@id,'$element') and @type='text']")->setValue('');
                        }
                        $this->postValueToXpath("//input[contains(@id,'$element') and @type='text']", $value);
                        try {
                            $this->clickXpath("//ul[@id='ui-id-1']/li/a[contains(.,'$value')]", 10);
                        } catch (\Exception $e) {
                            if ($label === 'Desired Skills' && $value === 'Custom' && $activeInterface === 'employers') {
                                //JobsWithSkillByContact.feature, J-80 and J-85
                                $findElement = $this->findXpathElement("//label[text()='$translatedLabel']/following::input[@id='dnf_class_values_job__skills__dnf_multiplerelation_picks___']");
                                $findElementClick = $findElement->keypress(13);
                            } else {
                                $nameParts = array_reverse(explode(' ', $value));
                                $stuName = array_shift($nameParts) . ', ';
                                $stuName .= implode(' ', $nameParts);
                                $xpath = "//div[starts-with(@id,'ac_pick_id_') and (contains(.,'$value') or contains(.,'$stuName'))] | //typeahead-container[contains(@class, 'dropdown open bottom')]/button[contains(@class, 'dropdown-item')]/span/mark[text()='$value' or text()='$stuName'] | //div[@class='ui-menu-item-wrapper' and (text()='$value' or text()='$stuName')]";
                                $empName = $page->find('xpath', $xpath);
                                if ($empName) {
                                    $this->focusAndClick($xpath, $empName);
                                } else {
                                    $page->find('xpath', "//input[contains(@id,'$element') and @type='text']")->keypress(13);
                                }
                            }
                        }
                    } else {
                        $element = $page->find('xpath', "//span[text()='$label']");
                        if ($element && $element->hasAttribute('id')) {
                            $id = $element->getAttribute('id');
                            $id = str_replace('field-label', '', $id);
                            $this->postValueToXpath("//input[starts-with(@id,'$id') and starts-with(@aria-describedby,'$id')]", $value);
                            $this->clickXpath("//div[starts-with(@id,'ac_pick_id_') and contains(.,'$value')]");
                        } else {
                            throw new \Exception("Field '$label' not found");
                        }
                    }
                }
            }
        }
    }

    /**
     * @Given I close :modal window
     */
    public function iClose($modal)
    {
        $modal = "Close_of_$modal";
        $this->iClickToButton($modal);
    }

    /**
     * @When I click to :buttonLabel button
     */
    public function iClickToButton($buttonLabel)
    {
        $this->iWillSeeLoadingIndicator();
        $buttonElement = $this->translate($buttonLabel);
        $this->acceptConfirmation();
        if ((strpos($buttonLabel, "Learn about {") !== false) && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $translatedLearnAboutText = [
                'AR-CSM' => 'تعرف على',
                'ES-CSM' => 'Obtener información sobre',
                'PT-CSM' => 'Aprenda sobre'
            ];
            $translatedLearnAboutText = $translatedLearnAboutText[self::$tag];
            $translatedValue = str_replace("Learn about", "$translatedLearnAboutText", $buttonLabel);
            $buttonElement = str_replace([ '{', '}' ], '', $translatedValue);
        }
        if ($buttonLabel === 'Show in a Separate Window') {
            $xpath = "//a[@id='separate_window_link']";
        } elseif ($buttonLabel === 'faculty_search') {
            $xpath = "(//input[@value='search'])[3]";
        } elseif ($buttonLabel === 'clear' || $buttonLabel === 'Search' || $buttonLabel === 'Sign In') {
            $xpath = "//*[(@value='$buttonElement' or @title='$buttonElement') and (@type='submit' or @type='button')] | //button[contains(text(),'$buttonElement')]";
        } elseif ($buttonLabel === 'Search Filters') {
            $xpath = "//div[contains(@class,'buttonbar')]/input[normalize-space(@value)='" .$this->translate("Search"). "'] | //div[contains(@class,'fgtitle')]//following::a[text()='$buttonElement']";
        } elseif ($buttonLabel === 'Clear Filters') {
            $xpath = "//div[contains(@class,'buttonbar')]/input[contains(@name,'search_filters') or contains(@value,'" .$this->translate("clear"). "')]";
        } elseif ($buttonLabel === 'newinfosession' || $buttonLabel === 'newschedule') {
            if (self::$tag === 'PT-CSM' || self::$tag === 'ES-CSM') {
                $date1 = $this->translateDateMonth('M', $this->actualDate);
                $date2 = $this->translateDateMonth('F', $this->actualDate);
                $xpath = "//a[contains(.,'$date1') or contains(.,'$date2')]";
            } elseif (self::$tag === 'AR-CSM') {
                $date1 = $this->translateDate($this->actualDate);
                $date2 = $this->translateDate($this->actualDate);
                $xpath = "//a[contains(.,'$date1') or contains(.,'$date2')]";
            } else {
                $date1 = date("M d, Y", strtotime($this->actualDate));
                $date2 = date("F d, Y", strtotime($this->actualDate));
                $date3 = date("M jS", strtotime($this->actualDate));
                $xpath = "//a[contains(.,'$date1') or contains(.,'$date2') or contains(.,'$date3')]";
            }
        } elseif (strpos($buttonLabel, '_Slot')) {
            [$date, $time] = explode('_', $buttonLabel);
            $slot = date("M d, Y", strtotime($date)) . ', ' . $time;
            $xpath = "//a[text()='$slot']";
        } elseif ($buttonLabel === 'Apply submit') {
            $translatedText = $this->translate('Submit');
            $xpath = "//div[@class='modal-content']//button[text()='$translatedText']";
        } elseif (in_array($buttonLabel, ['saved search', 'More Info', 'Clear all', 'Access this Book', 'match'])) {
            $xpath = "//a[contains(.,'$buttonElement')]";
        } elseif ($buttonLabel === 'Sanity Law') {
            $xpath = ".//h4[contains(text(),'$buttonElement')]";
        } elseif (in_array($buttonLabel,
            ['Continue', 'No, Thanks', 'Generate Report', 'ACCEPT', 'Add To my Top 10', 'Remove from Top 10'])) {
            $xpath = "//*[contains(text(),'$buttonElement') and not(contains(@class,'btn_submit')) or @value='$buttonElement']";
        } elseif ($buttonLabel === 'Select...') {
            $xpath = "//a[@class='chosen-single chosen-default']/span[text()='" . $this->translate("Select...") . "']";
        } elseif ($buttonLabel === 'Invoice id') {
            $element = "contains(@href,'invmode=form')";
            $xpath = "//a[$element]";
        } elseif (in_array($buttonLabel, ['View', 'btn_launch', 'phplivechat_login', 'view'])) {
            $xpath = "//img[contains(@src,'$buttonLabel.gif')]";
        } elseif ($buttonLabel === 'AdvancedSearch') {
            $xpath = "//span[@class='ajax_advanced_search_submits']/input[@value='Search']";
        } elseif ($buttonLabel === 'Program Evaluation' || $buttonLabel === 'Employer Evaluation') {
            $xpath = "//div[contains(text(),'$buttonElement')]";
        } elseif (in_array($buttonLabel, ['Save As Excel', 'Generate Publication', 'Job Alerts'])) {
            $xpath = "//button[contains(.,'$buttonElement')]";
        } elseif ($buttonLabel === 'OnestopSearch') {
            $xpath = ".//*[@id='school-picker-keyword-search']//following::span[2]";
        } elseif ($buttonLabel === 'EditRecord' || $buttonLabel === 'ReviewRecord') {
            $xpath = "//tr[contains(@id,'row_')]//a[@title='Edit' or @title='Review']";
        } elseif ($buttonLabel === 'OnestopSchoolSelection') {
            $xpath = ".//*[contains(text(),'Choose your own target schools')]";
        } elseif ($buttonLabel === 'Student Kiosk' || $buttonLabel === 'Counseling') {
            $xpath = "//td[contains(.,'$buttonElement')]";
        } elseif ($buttonLabel === 'upload') {
            $xpath = "//div[contains(@class,'buttonbar-bottom')]/child::input[@value='$buttonElement']";
        } elseif (strpos($buttonLabel, 'flag_of_') !== false) {
            [$action, $title] = explode('flag_of_', $buttonLabel);
            $xpath = "//*[@title='$title']";
        } elseif ($buttonLabel === 'Delete Report()' || $buttonLabel === 'Review Latest Run()') {
            $text1 = str_replace('()', '', $buttonLabel);
            $text1 = $this->translate($text1);
            $text = $text1 . "()";
            $xpath = "//img[@title='$text']";
        } elseif ($buttonLabel === 'Hide Column:Yes' || $buttonLabel === 'Hide Column:No') {
            $text = $this->translate("Hide Column");
            $val = (strpos($buttonLabel, 'Yes') !== false) ? "Yes" : "No";
            $xpath = "//*[text()='$text']//following::*[text()='" . $this->translate($val) . "']";
        } elseif (strpos($buttonLabel, '_of_') !== false) {
            [$action, $title] = explode('_of_', $buttonLabel);
            if ($action[0] === " " && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                $action = " " . $this->translate(trim($action));
                $title = ($title === "Job Title") ? $this->translate($title) : $title;
            } else {
                $action = $this->translate($action);
                $title = $this->translate($title);
            }
            $xpath = "//*[normalize-space(text())='$title' or @placeholder='$title']//following::*[(@value='$action' and @type='button') or text()='$action' or @title='$action' or @aria-label='$action'][1] | //span[text()='$title']/ancestor::div[@class='tr']//following-sibling::div[@class='icon-cell']//a[@class='$action']";
        } elseif (strpos($buttonLabel, ' of ') !== false) {
            [$action, $title] = explode(' of ', $buttonLabel);
            $action = $this->translate($action);
            $xpath = "//div[contains(.,'$title') and @class='list-item-body']//input[@value='$action'] | //a[starts-with(text(),'$title')]//following::p[@class='int-schedule']//a[1] | //h2[text()='$title']/../..//input[@alt='$action' or @value='$action'] | //h3[text()='$title']/../..//input[@alt='$action' or @value='$action']";
        } elseif (strpos($buttonLabel, '_on_') !== false) {
            [$action, $title] = explode('_on_', $buttonLabel);
            $translatedAction = $this->translate($action);
            if ($action === 'Delete Section' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                if ($title === 'Display') {
                    $translatedText = ["PT-CSM" => "Exibir", "AR-CSM" => "عرض", "ES-CSM" => "Mostrar"];
                    $title = $translatedText[self::$tag];
                } else {
                    $title = $this->translate($title);
                }
            }
            $xpath = "//span[text()='$title']//following::input[@value='$translatedAction' and @type='button' or @type='submit'][1]";
        } elseif (strpos($buttonLabel, ' for ') !== false) {
            [$action, $title] = explode(' for ', $buttonLabel);
            $action = $this->translate($action);
			$xpath = "//td[normalize-space(text())='$title']/preceding::button[contains(.,'$action')][1] | //div[@class='entity-checked' and text()='$title']/ancestor::div[contains(@data-entities, ' ')]/div//following::*[@class='$action']";
        } elseif (strpos($buttonLabel, 'visible') !== false) {
            $buttonElement = str_replace('visible', '', $buttonLabel);
            $buttonElement = $this->translate($buttonElement);
            $xpath = "//*[text()]/following::md-content[last()]/child::md-option/div[text()='$buttonElement']";
        } elseif (strpos($buttonLabel, 'delete_icon_') !== false) {
            $buttonElement = str_replace("delete_icon_", '', $buttonLabel);
            $xpath = "//*[text()='$buttonElement']//following::*[@class='md-chip-remove ng-scope'][1]";
        } elseif (strpos($buttonLabel, 'Date Select Year') !== false) {
            $xpath = "//*[contains(@aria-label,'$buttonLabel')]";
        } elseif (strpos($buttonLabel, 'Group Permission:') !== false || strpos($buttonLabel, 'Add Fields:') !== false) {
            $buttonElement = str_replace(['Group Permission:', 'Add Fields:'], '', $buttonLabel);
            $xpath = "//li[text()='" . $this->translate($buttonElement) . "'] | //ul/li/em[text()='" . $this->translate($buttonElement) . "']";
        } elseif (strpos($buttonLabel, '_flag_') !== false) {
            [$action, $title] = explode('_flag_', $buttonLabel);
            $action = $this->translate($action);
            $xpath = "//input[@value='$title']/following::button[@title='$action'][1]";
        } elseif (strpos($buttonLabel, '_link_') !== false) {
            [$action, $title] = explode('_link_', $buttonLabel);
            $action = $this->translate($action);
            $xpath = "//*[contains(text(),'$title') or a[normalize-space()='$title']]/ancestor::tr/descendant::*[@title='$action' or @alt='$action' or @value='$action' or text()='$action']";
        } elseif (strpos($buttonLabel, '_in_') !== false) {
            [$dateText, $job] = explode('_in_', $buttonLabel);
            $xpath = "//a[starts-with(text(),$job)]/following::p[@class='int-schedule']";
        } elseif ($buttonLabel === "See Who's Coming!" || $buttonLabel === "Don't Delete" || $buttonLabel === "Don't Finalize") {
            $xpath = "//a[@title=\"$buttonLabel\" and @class=\"btn\"] | //button[text()=\"$buttonLabel\" and @class=\"btn\"]";
        } elseif ($buttonLabel === 'More Filters') {
            $collapse = $this->translate('Fewer Filters');
            $fewer = $this->getSession()->getPage()->find('xpath', "//input[@value='$collapse']");
            if ($fewer) {
                return true;
            }
            $xpath = "//input[@value='$buttonElement']";
        } elseif ($buttonLabel === 'Add Fields') {
            $xpath = "//div[@title='$buttonLabel']";
        } elseif ($buttonLabel === 'Apply') {
            $xpath = "//button[contains(@class, 'ng-star-inserted')]/span[contains(text(),'$buttonElement')] | //div[@id='tabAddColumns']//button[contains(text(),'$buttonElement')] | //list-dynamic-form-filter[contains(@class, 'ng-star-inserted')]//button[@aria-expanded='true']/..//button[contains(text(),'$buttonElement')]";
        } elseif ($buttonLabel === 'User Menu') {
            $xpath = "//div[@id='user-avatar']//button[contains(@aria-label,'User Menu')] | //div[contains(@class, 'contact-chip')]//*[contains(@aria-label,'User Menu')]";
        } elseif ((strpos($buttonLabel, '_filter_') !== false) && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            [$action, $title] = explode('_filter_', $buttonLabel);
            $translatedAction = $this->translate($action);
            $translatedText = ["PT-CSM" => "Aplicar", "AR-CSM" => "تقديم طلب", "ES-CSM" => "Aplicar"];
            $title = $translatedText[self::$tag];
            $xpath = "//button/span[normalize-space(text())='$translatedAction']/following::button[text()='$title'][1]";
        } elseif ($buttonLabel === 'Run') {
            $xpath = "//button[@id='runbtn' and contains(text(),'Run')]";
        } else {
            $normalizedButton = trim($buttonElement);
            $xpath = "//*[normalize-space(@value)='$normalizedButton' or @id='$buttonElement' or @name='$buttonElement' or normalize-space(@title)='$normalizedButton' or @title='[$buttonElement]' or normalize-space(text())='$normalizedButton' or @alt='$buttonElement' or @class='$buttonElement' or @span='$buttonElement' or @aria-label='$buttonElement' or @data-text-value='$buttonElement' or @ng-click='$buttonElement']";
        }
        $this->focusAndClick($xpath);
        if ($buttonLabel === 'Search Filters') {
            $page = $this->getSession()->getPage();
            if ($page->find('xpath', "//form[@id='pro_bono_default_form']")) {
                $this->clickXpath("//input[@value='back to list']");
            }
        }
        if ($buttonLabel === "cost_4") {
            $this->review = 1;
        } elseif ($buttonLabel === "Save Selections" && !$this->changedSettings) {
            array_pop($this->settingsNav);
        }
        $this->confirmPopup();
    }

    /**
     * @Given I will click on active :buttonLabel button
     * @Given I click :buttonLabel button for :record
     */
    public function iWillClickOnActiveButton($buttonLabel, $record = null)
    {
        $page = $this->getSession()->getPage();
        $buttonLabel = $this->translate($buttonLabel);
        if ($record !== null) {
            $xpath = "//div[normalize-space()='$record']/..//input[@value='$buttonLabel' and not(@disabled)]";
        } else {
            $xpath = "//input[@value='$buttonLabel' and (@type='button' or @type='submit') and not(@disabled)] | //*[text()='$buttonLabel' and not(@disabled)]";
        }
        $buttonExists = $page->find('xpath', $xpath);
        if (!$buttonExists) {
            throw new \Exception("Button '$buttonLabel' not found");
        }
        $this->clickXpath($xpath);
    }

    public function translateDate($date, $field = '')
    {
        switch ($this->locale) {
            case "ar_sa":
                $standard = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
                $eastern_arabic_symbols = ["٠", "١", "٢", "٣", "٤", "٥", "٦", "٧", "٨", "٩"];
                $day = date('j', strtotime($date));
                $month = date('F', strtotime($date));
                $year = date('Y', strtotime($date));
                $ampm = date('A', strtotime($date));
                $arabic_day = str_replace($standard, $eastern_arabic_symbols, $day);
                $arabic_month = $this->translate($month);
                $arabic_year = str_replace($standard, $eastern_arabic_symbols, $year);
                if ($field === 'Date Viewable') {
                    $only_text = str_replace(",", "", $date);
                    $split = explode(' ', $only_text);
                    $time = explode(':', $split[3]);
                    $hour = str_replace($standard, $eastern_arabic_symbols, $time[0]);
                    $min = str_replace($standard, $eastern_arabic_symbols, $time[1]);
                    $meridiem = explode("/", $this->translate('AM/PM'));
                    $arabic_ampm = ($ampm === 'AM') ? $meridiem[0] : $meridiem[1];
                    $translatedDate = $arabic_day . ' ' . $arabic_month . ' ' . $arabic_year . ' م ' . $hour . ':' . $min . ' ' . $arabic_ampm;
                } else {
                    $translatedDate = $arabic_day . ' ' . $arabic_month . ' ' . $arabic_year . ' م';
                }
                break;
            case ($this->locale === "pt_br" || $this->locale === "es_419"):
                $translatedDate = $this->translateDateMonth('M', $date);
                break;
            default:
                $translatedDate = date("M d, Y", strtotime($date));
        }
        return $translatedDate;
    }

    public function translateDateFormat($field, $date)
    {
        $translatedDateFormat = '';
        switch ($this->locale) {
            case ($this->locale === "pt_br" || $this->locale === "es_419"):
                if ($field === 'Date Viewable') {
                    $standard = [
                        "January",
                        "February",
                        "March",
                        "April",
                        "May",
                        "June",
                        "July",
                        "August",
                        "September",
                        "October",
                        "November",
                        "December"
                    ];
                    if (self::$tag === 'PT-CSM') {
                        $intl_months = [
                            "jan",
                            "fev",
                            "mar",
                            "abr",
                            "mai",
                            "jun",
                            "jul",
                            "ago",
                            "set",
                            "out",
                            "nov",
                            "dez"
                        ];
                    } else {
                        $intl_months = [
                            "ene",
                            "feb",
                            "mar",
                            "abr",
                            "may",
                            "jun",
                            "jul",
                            "ago",
                            "sep",
                            "oct",
                            "nov",
                            "dic"
                        ];
                    }
                    $date = str_replace($standard, $intl_months, $date);
                    $only_text = str_replace(",", "", $date);
                    $split = explode(' ', $only_text);
                    $translatedDateFormat = $split[1] . " de " . $split[0] . ". de " . $split[2] . " " . $split[3];
                } else {
                    $day = date('j', strtotime($date));
                    $month = date('F', strtotime($date));
                    $year = date('Y', strtotime($date));
                    $pt_month = mb_strtolower($this->translate($month));
                    $translatedDateFormat = $day . " de " . $pt_month . " de " . $year;
                }
                break;
            case "ar_sa":
                $translatedDateFormat = $this->translateDate($date, $field);
        }
        return $translatedDateFormat;
    }

    public function translateDateMonth($mon, $date)
    {
        $month = '';
        if ($mon === 'M' || $mon === 'ocr_month') {
            $month = date('M', strtotime($date));
            $standard = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            if (self::$tag === 'PT-CSM') {
                $intl_months = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];
            } else {
                $intl_months = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
            }
            $month = str_replace($standard, $intl_months, $month);
        } elseif ($mon === 'F') {
            $month = date('F', strtotime($date));
            $month = mb_strtolower($this->translate($month));
        }
        $day = date('j', strtotime($date));
        $year = date('Y', strtotime($date));
        return "$day de $month. de $year";
    }

    public function translateCountry($value, $locale)
    {
        switch ($locale) {
            case ($locale === "pt_br" || $locale === "es_419"):
                if ($value === 'United States') {
                    $pt_country = ["United States" => "Estados Unidos"];
                    $value = $pt_country[$value];
                } else {
                    $pt_country = ["Portugal" => "Portugal"];
                    $value = $pt_country[$value];
                }
                break;
            case "ar_sa":
                if ($value === 'United States') {
                    $ar_country = ["United States" => "الولايات المتحدة"];
                    $value = $ar_country[$value];
                } else {
                    $ar_country = ["Portugal" => "البرتغال"];
                    $value = $ar_country[$value];
                }
        }
        return $value;
    }

    /**
     * @Given I set list page size to :value
     */
    public function iSetListPageSize($value)
    {
        $activeInterface = $this->getActiveInterface();
        $element = $this->getSession()->getPage()->find('xpath',
            "//*[@id='_blocksizer' or @name='_blocksizer' or @value='_blocksizer' or @placeholder='_blocksizer'] | //select[contains(@class,'pagination-select') and @title='misc.Select the number of items to show per page']");
        if ($element) {
            try {
                $field = "_blocksizer";
                $this->fillField($field, $value);
            } catch (\Exception $e) {
                $element->selectOption($value);
            }
        } elseif ($activeInterface === 'students') {
            $label = $this->getSession()->getPage()->find('xpath',
                "//*[contains(@translate-attr-aria-label,'Rows per page')]");
            if ($label) {
                $show = $this->translate('Show');
                $value = $show . " " . $value;
                $label->selectOption($value);
            }
        } else {
            echo "Pagination was not available on the page";
        }
    }

    /**
     * @Given I submit and click :field
     */
    public function iSubmitAndClick($field)
    {
        $field = $this->translate($field);
        $this->clickXpath("//*[@value='submit']");
        $xpath = "//*[@value='$field' or text()='$field']";
        $this->clickXpath($xpath);
    }

    /**
     * @When I open :appt slot and :action with :notes
     */
    public function iOpenSlotAndSubmit($appt, $action, $notes)
    {
        $page= $this->getSession()->getPage();
        if ($appt === 'Interested') {
            $popupText = 'ProNet Message';
            $field = 'pronetIntestedMessage';
        } else {
            $popupText = 'Confirm Appointment';
            $field = 'Additional Notes';
        }
        $this->iClickToButton($appt);
        $this->iWillSee($popupText);
        $iFrameXpath = "//div[@class='ui-dialog-content ui-widget-content']/iframe";
        $iFrame = $this->findXpathElement($iFrameXpath);
        $iFrameId = $iFrame->getAttribute('id');
        $this->switchToIFrame($iFrameId);
        $this->fillField($field, $notes);
        if ($action === 'submit') {
            $this->iClickToButton("Submit Request");
            [$counselor, $time] = explode('_of_', $appt);
            $iFrameXpath = "//div[@class='ui-dialog-content ui-widget-content']/iframe";
            $element = $page->find('xpath', $iFrameXpath);
            if (!$element) {
                $this->iWillSee($time);
            } else {
                $this->iClickToButton('Close');
                $this->iWillNotSee($time);
                $this->iClickToButton($appt);
                $this->iWillSee($popupText);
                $iFrameXpath = "//div[@class='ui-dialog-content ui-widget-content']/iframe";
                $iFrame = $this->findXpathElement($iFrameXpath);
                $iFrameId = $iFrame->getAttribute('id');
                $this->switchToIFrame($iFrameId);
                $this->fillField($field, $notes);
                $this->iClickToButton("Submit Request");
            }
        } elseif ($action === 'send') {
            $this->iClickToButton("send");
        }
    }

    public function switchToIFrame($name)
    {
        try {
            $this->findXpathElement("//*[@*='$name']");
            $this->getSession()->switchToIFrame($name);
        } catch (\Exception $e) {
            parent::switchToIFrame($name);
        }
    }

    /**
     * @Given I click :button button without errors
     */
    public function iClickButtonWithoutErrors($button)
    {
        $this->iClickToButton($button);
        $this->iShouldSeeNoErrors();
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" before "([^"]*)"$/
     */
    public function iWillSeeFollowing($not, $first, $second)
    {
        $this->iWillSee($first);
        $first = trim($first);
        $second = trim($second);
        $xpath = "//*[contains(normalize-space(text()), \"$first\")]/following::*[contains(normalize-space(text()), \"$second\")] | //*[normalize-space(text())=\"$first\"]/following::input[@value=\"$second\"]"; 
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception(($not ? '' : 'Not ') . "'$first' '$second' expected sequence");
        }
    }

    /**
     * @Given /^I should see translated "([^"]*)" before "([^"]*)"$/
     */
    public function iShouldSeeTranslatedBefore($first, $second)
    {
        $first = $this->translate($first);
        $this->iShouldSeeBefore($first, $second);
    }

    /**
     * @When /^I (\w+) the filter "([^"]*)" from "([^"]*)"$/
     */
    public function iFilterTo($field_type, $filterName, $field)
    {
        $translatedFiltername = $this->translate($filterName);
        $this->iWillSee($field);
        $this->iClickAndSee($field, $filterName);
        $this->spin(function () use ($translatedFiltername, $field_type) {
            if ($field_type === 'select') {
                $this->checkOption($translatedFiltername);
            } elseif ($field_type === 'unselect') {
                $this->uncheckOption($translatedFiltername);
            }
            return true;
        });
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" as "([^"]*)"$/
     */
    public function iWillSeeAs($not, $field, $value)
    {
        $page = $this->getSession()->getPage();
        $labelElem = $this->findXpathElement("//label[normalize-space(text())='$field']");
        $id = $labelElem->getAttribute('for');
        if ($id) {
            $element = $page->findById($id);
            $tag = $element->getTagName();
            $valueInfield = null;
            switch ($tag) {
                case 'input':
                    $valueInfield = $element->getAttribute('value');
                    break;
                case 'select':
                    $function = <<<JS
                            (function(){
                                let selectElement = document.getElementById('$id');
                                return selectElement.options[selectElement.selectedIndex].text;
                            })()
JS;
                    $valueInfield = $this->getSession()->evaluateScript($function);
                    break;
            }
            if (!($valueInfield === $value) xor $not) {
                throw new \Exception($value . ' is ' . (($not) ? 'not ' : '') . 'expected but ' . $valueInfield . ' is found for ' . $field);
            }
        } else {
            throw new \Exception($field . ' is not found');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" field as read only$/
     */
    public function iWillSeeFieldAsReadOnly($not, $field)
    {
        $xpath = "//*[text()='$field']/../following-sibling::div[contains(@class,'readonly')]";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception('Field ' . $field . ' is ' . ($not ? ' found ' : 'not found ') . 'as readonly');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" in the "([^"]*)"$/
     */
    public function iWillSeeInThe($not, $value, $field)
    {
        $page = $this->getSession()->getPage();
        $id = $this->getIdFromLabelElement($field);
        if (!$id) {
            throw new \Exception('Field ' . $field . ' is not found');
        }
        if (!$page->find('xpath', "//*[@id='$id']/option[text()='$value']") xor $not) {
            throw new \Exception('Value ' . $value . ' is ' . ($not ? 'found' : 'not found') . ' in the field ' . $field);
        }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" in the titlebar$/
     */
    public function iWillSeeInTheTitlebar($not, $value)
    {
        $page = $this->getSession()->getPage();
        $titleBar = $page->find('css', 'div.titlebar h1');
        if ($titleBar) {
            if (($value === $titleBar->getText()) xor $not) {
                return true;
            }
            throw new \Exception($value . ' is ' . ($not ? '' : 'not ') . 'found in titlebar');
        }
        throw new \Exception('Title bar not found for specified selector');
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" button for "([^"]*)"$/
     */
    public function iWillSeeButtonFor($not, $button, $record)
    {
        $page = $this->getSession()->getPage();
        $exist = $page->find('xpath', "//a[text()='$record']/following::div/a/span[normalize-space(text())='$button']");
        if (!$exist xor $not) {
            throw new \Exception("Button $button is" . ($not ? ' ' : ' not ') . "found for $record");
        }
    }

    /**
     * @Then /^I click to "([^"]*)" button for "([^"]*)"$/
     */
    public function iClickToButtonFor($option, $record)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//a[text()='$record']/../parent::div/../div/button/span/i[contains(@class,'$option')] | //span[normalize-space(text())='$record']//following::span[text()='$option']/parent::a | //span[normalize-space(text())='$record']/button[contains(@aria-label,'$option')] | //h2[text()='$record']/./parent::div/following-sibling::div[1]//a/span[text()='$option'] | //h3[text()='$record']/parent::div/following::div[1]//logo-upload[@class='ng-star-inserted']//img[@alt='$option']";
        $field = $page->find('xpath', $xpath);
        if (!$field) {
            throw new \Exception("Button $option is not found for $record");
        }
        $this->clickXpath($xpath);
    }

    /**
     * @Given /^I perform "([^"]*)" operation on field "([^"]*)"$/
     */
    public function iPerformOperationOnField($operation, $field)
    {
        $fieldExists = $this->getSession()->getPage()->find('xpath', "//div[text()='$field']/../ancestor::li");
        if ($fieldExists) {
            $fieldExists->mouseOver();
            $xpath = "//div[text()='$field']/../parent::div/following-sibling::div[2]/div/div[contains(@title,'$operation')]";
            $this->getSession()->getPage()->find('xpath', $xpath)->click();
            if ($operation === 'remove') {
                try {
                    $this->iConfirmPopup();
                } catch (\Exception $e) {
                    $this->iWillSee($field);
                }
            }
        } else {
            throw new \Exception($field . ' is not found');
        }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" value in "([^"]*)"$/
     */
    public function iWillSeeValueIn($not, $value, $field)
    {
        $xpath = "//select[contains(@id,'" . lcfirst($field) . "') or @js_required_field='$field']/../following-sibling::table/descendant::ul/li/span[text()='$value'] | //select[contains(@id,'" . mb_strtolower($field) . "') or contains(@id,'" . mb_strtolower(str_replace(" ","_",$field)) . "') or @display_name= '$field' or @js_required_field='$field']/option[@selected='selected' and contains(text(),'$value')] | //input[contains(@id,'" . lcfirst($field) . "') or @js_required_field='$field' and @value='$value'] | //label[text()='$field']/following::div[contains(@class, 'chosen-container')]/a[@class='chosen-single']/span[text()='$value']";
        $fieldExists = $this->getSession()->getPage()->find('xpath', $xpath);
        if (!$fieldExists xor $not) {
            throw new \Exception("value $value is" . ($not ? ' ' : ' not ') . "found for $field");
        }
    }

    /**
     * @Given I :permit student profile
     */
    public function iPublishStudent($permit)
    {
        $text = $this->getSession()->getPage()->find('xpath', "//span[text()='" . $this->translate("Your profile is ready.")."']");
        if (!$text && ($permit === 'publish')) {
            $this->clickXpath("//*[contains(@class,'ng-empty') and @aria-checked='false']//following::div[@class='md-thumb-container']");
        } elseif ($text && ($permit === 'unpublish')) {
            $this->clickXpath("//*[contains(@class,'ng-not-empty') and @aria-checked='true']//following::div[@class='md-thumb-container']");
        } elseif (($text && ($permit === 'publish')) ||  (!$text && ($permit === 'unpublish'))) {
            return true;
        }
    }

    /**
     * @Given I :action Custom dashboard :title
     */
    public function iCustomDashboard($action, $title)
    {
        $page = $this->getSession()->getPage();
        $buttonExists = $page->find('xpath', "//*[normalize-space()='$title']/preceding-sibling::td[not(@style) or @style]/div/div/span/button[normalize-space()='" . ucfirst($action) . "' or @title='$action']");
        if ($buttonExists) {
            $buttonExists->click();
            if (ucfirst($action) === 'Delete') {
                $this->iWillSee('Are you sure you want to delete this dashboard? Doing so is final and cannot be undone.');
                $this->iClickToButton('Delete_of_Delete Dashboard?');
            } else {
                $this->iWillSee($title);
            }
        } else {
            throw new \Exception($action . ' button not found for ' . $title);
        }
    }

    /**
     * @When I click :action icon for :title record
     */
    public function iClickIconFor($action, $title)
    {
        $page = $this->getSession()->getPage();
        if (strpos($title, ':') === false) {
            $xpath = "//div[contains(.,'$title')]/preceding::a[text()='$action' or @title='$action'][1]";
        } else {
            [$section, $label] = explode(':', $title);
            $sectionXpath = "//h2[text()='$section']/../..";
            $viewMore = $page->find('xpath', $sectionXpath . "//a[text()='View More']");
            if ($viewMore) {
                $viewMore->click();
            }
            $xpath = $sectionXpath . "//div[normalize-space(text())='$label']/preceding::a[text()='$action'][1]";
        }
        $element = $page->find('xpath', $xpath);
        if ($element) {
            $element->focus();
            $element->click();
            if ($action === 'Delete') {
                $this->iConfirmPopup();
            }
        } else {
            throw new \Exception($action . ' is not found for ' . $title);
        }
    }

    /**
     * @When I click :button button for :label template
     */
    public function iClickButtonForTemplate($button, $label)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//div[normalize-space(text())='$label']/ancestor::td/div/input[@type='button' and @value='$button']";
        $templateSelect = $page->find('xpath', $xpath);
        if ($templateSelect === null) {
            throw new \Exception($label . ' template not found');
        }
        $templateSelect->focus();
        $templateSelect->click();
    }

    /**
     * @When /^(?:|I )should see "([^"]*)" in popup$/
     */
    public function assertPopupMessage($message, int $spins = 80)
    {
        $driver = $this->getSession()->getDriver();
        $this->spin(static function ($context) use ($message, $driver) {
            try {
                $alertText = $driver->getWebDriverSession()->getAlert_text();
            } catch (\Exception $e) {
                $alertText = $driver->getWebDriverSession()->getAlert_text();
            }
            if ($alertText) {
                if ($message === 'You must select {Schedule to Copy}.') {
                    $substring = $context->translate('Schedule to Copy');
                    $message = str_replace('Schedule to Copy', $substring, $message);
                }
                $message = $context->translate($message);
                if ($alertText === $message) {
                    return true;
                }
                $driver->getWebDriverSession()->accept_alert();
                throw new \Exception("Expected '$message' alert but got '$alertText'");
            }
        }, $spins);
    }

    /**
     * @When /^I confirm popup$/
     */
    public function iConfirmPopup()
    {
        $driver = $this->getSession()->getDriver();
        if (is_callable([$driver, 'getWebDriverSession'])) {
            $driver->getWebDriverSession()->accept_alert();
        }
    }

    // Handle javascript alert for Phantomjs and browsers
    public function acceptConfirmation()
    {
        $this->getSession()->getDriver()->executeScript('window.confirm = function(){return true;}');
    }

    /**
     * @Then I Search :text and click
     */
    public function iSearchAndClick($text)
    {
        $this->acceptConfirmation();
        $page = $this->getSession()->getPage();
        $translatedText = $this->translate($text);
        $xpath = "//span[contains(text(),'" . $translatedText . "')]";
        $action = null;
        if ($text === 'Apply' || $text === 'continue' || $text === 'Search') {
            $xpath = "//button[contains(text(),'" . $translatedText . "')]";
        } elseif ($text === 'Continue') {
            $xpath = "//*[@value='$translatedText' and @type='submit']";
        } elseif ($text === 'Test Student1' || $text === 'Test Student3') {
            $xpath = "//a[contains(.,'$text')]/following::select[1]";
        } elseif ($text === 'Payment Id') {
            $xpath = "//a[contains(@href,'paymode=form')]";
        } elseif (strpos($text, 'Filters') !== false) {
            $filters = $this->translate('Filters');
            $apply = $this->translate('Apply');
            $xpath = "//h3[text()='$filters']//following::button[contains(text(),'$apply')]";
        } elseif (strpos($text, ' of ') !== false) {
            [$action, $title] = explode(' of ', $text);
            $translatedAction = $this->translate($action);
            if (($action !== 'Express Interest')
                && (strpos($title, 'OCR') === false)
                && (strpos($action, 'Attach') === false)
                && (strpos($action, 'Reschedule') === false)
                && (strpos($action, 'Option') === false)
            ) {
                $retry = 0;
                $this->iSetListPageSize(250);
                $element = $page->find('xpath', "//span[text()='$translatedAction']");
                while (!$element && ($retry < 5)) {
                    $this->iWait(5000);
                    $element = $page->find('xpath', "//span[text()='$translatedAction']");
                    ++$retry;
                }
                if ($element) {
                    $xpath = "//div[contains(.,'$title') and @class='list-item-body']//span[text()='$translatedAction']";
                }
            } else {
                if ($action === 'Attach Position') {
                    $toggle = $page->find('xpath',
                        "//li[contains(.,'$title')]/child::div//button[@class='actions-toggle']");
                } else {
                    $toggle = $page->find('xpath',
                        "//li[contains(.,'$title')]/child::div[@class='list-item-actions']//div[contains(@class,'-toggle')]/child::*[contains(@class,'-toggle')]");
                }
                if ($action === "Option Menu") {
                    $xpath = "//*";
                } else {
                    $xpath = "//li[contains(.,'$title')]/child::div[@class='list-item-actions']//a[contains(.,'$translatedAction')]";
                }
                if ($toggle) {
                    $toggle->focus();
                    $toggle->click();
                    $this->acceptConfirmation();
                }
            }
        }
        $element = $page->find('xpath', $xpath);
        if ($element) {
            if ($action !== "Option Menu") {
                $element->focus();
                $element->click();
            }
        } else {
            throw new \Exception('Target element ' . $text . ' was not found');
        }
    }

    /**
     * @Then I expand :section section
     */
    public function expandSection($section)
    {
        $xpath = "//*[text()=\"$section\"]//following::a[@title='View All'][1]";
        $this->clickXpath($xpath);
    }

    /**
     * @Then /^I should (not )?see result for "([^"]*)" as "([^"]*)"$/
     */
    public function iShouldSeeResult($not, $field, $value)
    {
        $page = $this->getSession()->getPage();
        if ($field === 'Major' && self::$tag === 'LAW-CSM') {
            $field = 'Practice Area';
        }
        if ($field === 'Attached Document' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $field = str_replace('<br>', ' ', $this->translate('Attached<br>Document'));
        } else {
            $field = $this->translate($field);
        }
        if ($value === "(student declined)") {
            $text = $this->translate('student declined');
            $value_translated = "(" . $text . ")";
        } elseif ($value !== 'Download File') {
            $value_translated = $this->translate($value);
        }
        $label_elements = $page->findAll('xpath', "//th");
        foreach ($label_elements as $key => $label) {
            if (mb_strstr($label->getText(), $field)) {
                $matchedKeyPosition = $key;
                break;
            }
        }
        if (isset($matchedKeyPosition)) {
            $row_elements = $page->findAll('xpath', "//table/tbody/tr[contains(@id,'row_')]");
            if (count($row_elements) > 0) {
                $targetText = [];
                foreach ($row_elements as $key => $row) {
                    $target_elements = $page->findAll('xpath',
                        "//table/tbody/tr[contains(@id,'row_')][" . ($key + 1) . "]/td");
                    $target = $target_elements[$matchedKeyPosition];
                    $targetText[] = $target->getText();
                }
                if (in_array($value, $targetText) xor $not) {
                    return $target;
                }
                throw new \Exception('Value ' . $value . ' was ' . ($not ? '' : 'not ') . 'found for ' . $field);
            } else {
                $row_elements = $page->findAll('xpath', "//table[@id='SQLReportTable']/tbody/tr");
                if (count($row_elements) > 0) {
                    foreach ($row_elements as $key => $row) {
                        $target_elements = $page->findAll('xpath',
                            "//table[@id='SQLReportTable']/tbody/tr[" . ($key + 1) . "]/td");
                        $target = $target_elements[$matchedKeyPosition];
                        if (!empty($target) && ($target->getText() === $value || $target->getText() === $value_translated)) {
                            return $target;
                        }
                    }
                } else {
                    $row_elements = $page->findAll('xpath', "//table/tbody/tr[contains(@class,'cspList_main') or contains(@class,'data-table-row')]");
                    if (count($row_elements) > 0) {
                        foreach ($row_elements as $key => $row) {
                            $target_elements = $page->findAll('xpath',
                                "//table/tbody/tr[contains(@class,'cspList_main') or contains(@class,'data-table-row')][" . ($key + 1) . "]/td");
                            $target = $target_elements[$matchedKeyPosition];
                            if (!empty($target) && ($target->getText() === $value || $target->getText() === $value_translated)) {
                                return $target;
                            }
                        }
                    } else {
                        $row_elements = $page->findAll('xpath',
                            "//table[@class='ocr_interview_schedule']/tbody//td/parent::tr");
                        if (count($row_elements) > 0) {
                            foreach ($row_elements as $key => $row) {
                                $target_elements = $page->findAll('xpath',
                                    "//table[@class='ocr_interview_schedule']/tbody/tr[" . (($key + 1) * 2) . "]/td");
                                $target = $target_elements[$matchedKeyPosition];
                                if (!empty($target) && $target->getText() === $value) {
                                    return $target;
                                }
                            }
                        } else {
                            $rowElements = $page->findAll('xpath', "//table[@class='event-log-diff']");
                            if (count($rowElements)) {
                                $targetElements = $page->findAll('xpath', "//table[@class='event-log-diff']/tbody/tr/td[contains(text(),'$value')]");
                                $target = $targetElements[($matchedKeyPosition * 0)];
                                if (empty($target) || $target->getText() !== $value) {
                                    throw new \Exception("Value '$value' was not found for '$field' column");
                                }
                            } else {
                                $rowElements = $page->findAll('xpath', "//table/tbody/tr/td");
                                if (count($rowElements)) {
                                    $target = $rowElements[$matchedKeyPosition];
                                    if ((empty($target) || $target->getText() !== $value) xor $not) {
                                        throw new \Exception('Value ' . $value . ' was ' . ($not ? '' : 'not ') . 'found for ' . $field);
                                    }
                                } else {
                                    throw new \Exception("Data not found in table");
                                }
                            }
                        }
                    }
                }
            }
        } else {
            throw new \Exception("Value '$value' was not found for '$field'");
        }
    }

    public function readDateFromCalWidget($period)
    {
        $page = $this->getSession()->getPage();
        $calMonthYear = $page->find('xpath', "//div[contains(@style,'display: block')]/table/thead/tr[1]/td[2]");
        if ($calMonthYear) {
            $array = preg_split('/[ ,]+/', trim($calMonthYear->getText()));
        } else {
            throw new \Exception("Could not find month in the Calendar widget for $period");
        }
        return (($period === "month") ? $this->translate($array[0]) : $array[1]);
    }

    /**
     * @Then I select :date for :field
     */
    public function iSelectDate($field, $date)
    {
        $field = $this->translate($field);
        $date = explode('-', $date);
        $expected_year = $date[0];
        $expected_month = $this->translate($date[1]);
        $expected_day = $date[2];
        if (strpos($field, '[_start]') !== false || strpos($field, '[_end]') !== false) {
            $this->clickXpath("//*[@name='$field']/following::input[contains(@onclick,'alendar')]");
        } else {
            $this->clickXpath("//*[contains(text(),'$field')]/following::input[3][contains(@onclick,'alendar')]");
        }
        $month = $this->readDateFromCalWidget("month");
        while ($month !== $expected_month) {
            if (date('n', strtotime($month)) < date('n', strtotime($expected_month))) {
                $this->clickXpath("//div[contains(@style,'display: block')]/table/thead/tr[2]/td[4]");
            } else {
                $this->clickXpath("//div[contains(@style,'display: block')]/table/thead/tr[2]/td[2]");
            }
            $month = $this->readDateFromCalWidget("month");
        }
        $year = $this->readDateFromCalWidget("year");
        while (is_numeric($year) && ($year !== $expected_year)) {
            if ($year < $expected_year) {
                $this->clickXpath("//div[contains(@style,'display: block')]/table/thead/tr[2]/td[5]");
            } else {
                $this->clickXpath("//div[contains(@style,'display: block')]/table/thead/tr[2]/td[1]");
            }
            $year = $this->readDateFromCalWidget("year");
        }
        $this->clickXpath("//div[contains(@style,'display: block')]/table/tbody/tr/td/button[text()='$expected_day']");
    }

    protected function findLoadingIndicator($page): bool
    {
        return !empty($page->find('xpath', "//*[@class='busy' or @class='loading' or @class='dhx_loading' or @class='loading-table.loading-spinner']"));
    }

    /**
     * @When I choose date in calendar widget
     */
    public function iChooseDateInCalendarWidget(TableNode $table)
    {
        $this->iWillSeeLoadingIndicator();
        foreach ($table->getHash() as $record) {
            $date = $record['date'];
            $dateMonth = date("Y-F-d", strtotime($date));
            [$year, $month, $day] = explode('-', $dateMonth);
            $day_padded = sprintf("%02d", $day);
            switch ($record['current date']) {
                case "yes":
                    $xpath = "//td[contains(@class,'dhx_now')]";
                    $this->focusAndClick($xpath);
                    break;
                case "no":
                    $xpath = "//div[text()='" . $month . " " . $year . "']/following-sibling::div[@class='dhx_year_body']/table/tbody/tr/td[@class !='dhx_before ' and @class !='dhx_before' and @class !='dhx_after ' and @class !='dhx_after' and (@class = ' ' or @class = '' or not(@class))]/div[text()='" . $day_padded . "']";
                    $this->focusAndClick($xpath);
                    break;
            }
        }
    }

    /**
     * @When I will see date and time in page as
     */
    public function iWillSeeDateTimeInPage(TableNode $table)
    {
        foreach ($table->getHash() as $record) {
            $date = $record['date'];
            if (!empty($record['time'])) {
                $time = $record['time'];
                $datemonth = date("Y-M-d", strtotime($date));
                [$year, $month, $day] = explode('-', $datemonth);
                $day_padded = sprintf("%02d", $day);
                $text = $month . ' ' . $day_padded . ', ' . $year . ', ' . $time;
            } else {
                $datemonth = date("Y-F-j", strtotime($date));
                [$year, $month, $day] = explode('-', $datemonth);
                $text = $month . ' ' . $day . ', ' . $year;
            }
            $this->iWillSee($text);
        }
    }

    /**
     * @When I will see :event at :time in :tab view
     */
    public function iWillSeeEventInCalendarView($event, $time, $tab)
    {
        $this->iWillSeeLoadingIndicator();
        $page = $this->getSession()->getPage();
        switch ($tab) {
            case "day":
            case "counselors":
            case "week":
            case "month":
                $xpath = $page->find('xpath', "//div[contains(@class,'cal_event')][contains(.,'$time')]");
                $element = ($tab === "month") ? "[contains(.,'$event')][1]" : "/div[contains(.,'$event')][1]";
                $eventVisible = $page->find('xpath',
                    "//div[contains(@class,'cal_event')][contains(.,'$time')]$element");
                if ($xpath) {
                    if (!$eventVisible) {
                        throw new \Exception("$event not found at the time $time");
                    }
                } else {
                    throw new \Exception("$event for timing $time not found in the $tab view");
                }
                break;
            case "current day in year":
                $this->clickXpath("//td[contains(@class,'dhx_now')]");
                $this->iWillSeeEventInCalendarView($event, $time, "day");
        }
    }

    /**
     * @When I click date in year calendar
     */
    public function iClickDateInYearCalendar(TableNode $table)
    {
        $this->iWillSeeLoadingIndicator();
        $page = $this->getSession()->getPage();
        foreach ($table->getHash() as $record) {
            $fixedYear = $record['year'];
            $fixedMonthText = $record['month'];
            $fixedDate = $record['date'];
            $numMonth = date("m", strtotime($fixedMonthText));
            $monthName = date("Y-F-d", strtotime($fixedYear));
            $findYear = explode('-', $monthName);
            $numYear = $findYear[0];
            $xpath = $page->find('xpath',
                "//div[contains(.,'$fixedMonthText')]/following::a[contains(@href,'$numYear$numMonth$fixedDate')]");
            if ($xpath) {
                $xpath->click();
            } else {
                throw new \Exception("$xpath not found in the Year view");
            }
        }
    }

    /**
     * @When I will see :event as full day event
     */
    public function iWillSeeFullDayEvent($event)
    {
        $this->iWillSeeLoadingIndicator();
        $page = $this->getSession()->getPage();
        $xpath = $page->find('xpath', "//div[contains(@class,'dhx_cal_event_line')]/a[contains(.,'$event')]");
        if (!$xpath) {
            throw new \Exception("$event not found in the Day view");
        }
    }

    /**
     * @When I will see interviews :interviews as grouped to :gcount
     */
    public function iWillSeeInterviewsGrouped($interviews, $gcount)
    {
        $this->iWillSeeLoadingIndicator();
        $page = $this->getSession()->getPage();
        $ocr = $this->translate('Employers Conducting Interviews');
        $oci = $this->translate('OCI Sessions');
        $split = explode(',', $interviews);
        $groupcount = $page->find('xpath',
            "//div[contains(@class,'dhx_cal_event_line')][contains(.,'$ocr') or contains(.,'$oci')]/span[text()='$gcount']");
        if ($groupcount) {
            $groupcount->mouseOver();
            $this->iWait(2000);
            $checkcount = 0;
            while ($checkcount < $gcount) {
                $tooltip = $page->find('xpath',
                    "//div[@class='dhtmlXTooltip tooltip tooltip2']/a[text()='$split[$checkcount]']");
                if (!$tooltip) {
                    throw new \Exception("$split[$checkcount] not found in the Tooltip");
                }
                ++$checkcount;
            }
        } else {
            throw new \Exception("$interviews not found in the Day view");
        }
    }

    /**
     * @When I click counseling at :time for :student in counselors
     */
    public function iClickCounselingAtCounselors($time, $student)
    {
        $this->iWillSeeLoadingIndicator();
        $page = $this->getSession()->getPage();
        $xpath = $page->find('xpath', "//div[text()='$time']");
        if ($xpath) {
            $appt = $page->find('xpath', "//div[text()='$time']/following::a[contains(.,'Counseling Appt.')][1]");
            $stud = $page->find('xpath',
                "//div[text()='$time']/following::a[contains(.,'Counseling Appt.')][1]/preceding::a[contains(.,'$student')][1]");
            if ($appt && $stud) {
                try {
                    $appt->click();
                } catch (\Exception $e) {
                    $this->clickPageLink('', $page, '.event_text_wrapper > b > a:nth-of-type(2)');
                }
            } else {
                throw new \Exception("Counseling not found at the time $time to $student");
            }
        } else {
            throw new \Exception("Timing $time not found in Counselors calendar");
        }
    }

    /**
     * @When I pick :option for :field
     */
    public function iPickMajor2($option, $field, $optional = null)
    {
        $page = $this->getSession()->getPage();
        if (strpos($field, 'language') !== false) {
            $major_sp[0] = $option;
        } else {
            $major_sp = explode("/", $option);
        }
        $major_read_only = $page->find('xpath',
            "//div[contains(@id,'" . strtolower($field) . "')]/*[contains(@id,'scrollable_readonly_ms_field_')]");
        if ($major_read_only) {
            $major_read_only->focus();
            $this->iWait(3000);
        }
        if ($major_sp[0] === 'All Majors' && self::$tag === 'LAW-CSM') {
            $major_sp[0] = 'All Practice Areas';
        }
        $translatedSelect = $this->translate("Select");
        $translatedAdd = $this->translate("Add...");
        $translatedChoose = $this->translate("Choose...");
        $trimmedField = mb_substr($field, 1);
        $majorFieldXpath = "//*[contains(@aria-labelledby,'$trimmedField') or contains(@id,'$trimmedField')]//*[@title='$translatedSelect' or @value='$translatedAdd' or @value='$translatedChoose' or @aria-label='$translatedAdd' or @aria-label='$translatedChoose' or @class='hp_selection_text']";
        if ($field === 'Type') {
            $counselingLawTest = $page->find('xpath', "//h1[text()='" . $this->translate('Find Availability') . "']");
            if ($counselingLawTest) {
                $majorFieldXpath = "//td[@class='hp_selection_text']";
            }
        }
        $majorField = $page->find('xpath', $majorFieldXpath);
		if ($majorField && ($optional !== 'Student_Degree')) {
            $this->forceClickXpath($majorFieldXpath);
            $this->getSession()->wait(2000);
            $this->translate($field);
            $major_sp[0] = $this->translate($major_sp[0]);
            $majorMatch = "@title='$major_sp[0]' or text()='$major_sp[0]'";
            $hpKeyElem = $page->find('xpath', "//*[contains(@id,'hp_key') and ($majorMatch)]");
            if ($hpKeyElem) {
                $hpKeyElem->click();
            } elseif ($optional === 'Entity') {
                $this->getSession()->getDriver()->switchToIFrame("entities_modal_inner");
                $this->clickXpath("//input[@type='checkbox' and @data-name='$major_sp[0]'] | //label[text()='$major_sp[0]']/preceding-sibling::input[@type='checkbox']");
                if (count($major_sp) > 1) {
                    $this->clickXpath("//input[@type='checkbox' and @data-name='$major_sp[1]'] | //label[text()='$major_sp[1]']/preceding-sibling::input[@type='checkbox']");
                }
                $this->switchToMainFrame();
                $confirm = $page->find('xpath', "//div[@aria-describedby='entities_modal']/div[contains(@class, 'ui-dialog-buttonpane')]/div/button[text()='Confirm']");
                if ($confirm) {
                    $confirm->click();
                }
            } else {
                $this->clickXpath("//*[$majorMatch]");
            }
            if (count($major_sp) > 1) {
                $this->clickXpath("//div[@title='$major_sp[1]' or text()='$major_sp[1]'] | //span[@title='$major_sp[1]' or text()='$major_sp[1]']");
            }
            $done = $page->find('xpath', "//button[text()='" . $this->translate('Done') . "']");
            if ($done) {
                $done->click();
            }
        } elseif ($optional === 'Student_Degree') {
            $selectXpath = $page->find('xpath', "//div[@id='$field']//div[@title='$translatedSelect']");
            if ($selectXpath) {
                try {
                    $selectXpath->click();
                    $valueOne = $this->translate($major_sp[0]);
                    $valueTwo = null;
                    if (count($major_sp) > 1) {
                        $valueTwo = $this->translate($major_sp[1]);
                    }
                    if (!$valueTwo) {
                        $this->clickXpath("//div[contains(@style, 'visibility: visible')]//span[@role='listbox']/div[@title='$valueOne']");
                    } else {
                        $page->find('xpath', "//div[contains(@style, 'visibility: visible')]//span[@role='listbox']/div[@title='$valueOne']")->mouseOver();
                        $this->clickXpath("//div[contains(@style, 'visibility: visible')]//span[@role='listbox']/div[@title='$valueTwo']");
                    }
                } catch (\Exception $e) {
                    throw new \Exception("Multiselect field => $field not found.");
                }
            }
        } else {
            $major_sp[0] = $this->translate($major_sp[0]);
            $select = $page->find('xpath', "//select[contains(@id,'_$field')]");
            if ($select) {
                $select->selectOption($major_sp[0]);
            } else {
                $select = $page->find('xpath', "//input[contains(@id,'_$field')]");
                if ($select) {
                    $id = $select->getAttribute('id');
                    $this->postValueToXpath("//*[@id='$id']", $major_sp[0]);
                    $this->iWait(2000);
                    $this->clickXpath("//div[starts-with(@id,'ac_pick_id_') and contains(.,'$major_sp[0]')]");
                } else {
                    $fieldElem = null;
                    $num = explode('_', $field);
                    if (isset($num[1])) {
                        $fieldElem = $page->find('xpath', "//*[contains(@id,'hp_selection_{$num[1]}') and not(@class='hp_config')]");
                    }
                    if ($fieldElem) {
                        $fieldElem->click();
                        $this->clickXpath("//*[@title='{$major_sp[0]}' and contains(@id,'hp_key{$num[1]}')]");
                    } else {
                        throw new \Exception("Multiselect field => $field not found.");
                    }
                }
            }
        }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" Column in table$/
     */
    public function iWillSeeColumnInTable($not, $column)
    {
        $page = $this->getSession()->getPage();
        $column = $this->translate($column);
        $columns = $page->findAll('xpath', "//div[text()='Batch Options']/ancestor::table/tbody/tr[2]/th");
        if (!$columns) {
            $columnXpath = "//div[@class='data-table-box']/table/thead/tr/th | //*[@class='sorting_disabled']/ancestor::table/thead/tr/th/following::div[@class='dt-header-wrapper']//span | //div[@class='chart-legend']/table/thead/tr/th/div/p | //table[@aria-describedby='SQLReportTable_info']/thead/tr/th";
            $columns = $page->findAll('xpath', $columnXpath);
        }
        if (count($columns) > 0) {
            $labels = [];
            foreach ($columns as $value) {
                $labels[] = $value->getText();
            }
            if (in_array($column, $labels) xor $not) {
                return true;
            }
            throw new \Exception('column ' . $column . ($not ? '' : ' not') . ' found in table');
        }
        throw new \Exception('Table not found to see column');
    }

    /**
     * @Given /^I will (not )?see sort icon for "([^"]*)" column$/
     */
    public function iWillSeeSortIconForColumn($not, $column)
    {
        $page = $this->getSession()->getPage();
        $column = $this->translate($column);
        $xpath = "//div[text()='Batch Options']/ancestor::table/tbody/tr[2]/th/descendant::*[text()='$column']";
        $validColumn = $page->find('xpath', $xpath);
        if ($validColumn) {
            $sortableColumn = $page->find('xpath', $xpath . "/parent::a/descendant::span[@class='sort-icn']");
            if ($sortableColumn xor $not) {
                return true;
            }
            throw new \Exception('column ' . $column . ($not ? '' : ' not') . ' contains sort icon');
        }
        throw new \Exception('column ' . $column . ' is not found in the table');
    }

    /**
     * @Given /^I override approval for "([^"]*)" as "([^"]*)" with the comment "([^"]*)"$/
     * @Given /^I override approval for "([^"]*)" as "([^"]*)"$/
     */
    public function iOverrideApprovalForAsWithoutName($userType, $statusValue, $comment = null)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//div[text()='$userType']/following::div/button[contains(@aria-label,'Override')]";
        $this->scrollToElement($xpath);
        $overrideButton = $page->find('xpath', $xpath);
        if ($overrideButton !== null) {
            $this->focusAndClick($xpath);
            $this->iOverrideApprovalForAsWithTheComment($page, $statusValue, $comment);
        } else {
            throw new \Exception('Override button not found for ' . $userType);
        }
    }

    /**
     * @Given /^I override approval for "([^"]*)" "([^"]*)" as "([^"]*)" with the comment "([^"]*)"$/
     * @Given /^I override approval for "([^"]*)" "([^"]*)" as "([^"]*)"$/
     */
    public function iOverrideApprovalForAsWithName($userType, $name, $statusValue, $comment = null)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//div[text()='$name']/following::div/div[text()='$userType']/following::div/button[contains(@aria-label,'Override')]";
        $overrideButton = $page->find('xpath', $xpath);
        if ($overrideButton !== null) {
            $overrideButton->focus();
            $overrideButton->click();
            $this->iOverrideApprovalForAsWithTheComment($page, $statusValue, $comment);
        } else {
            throw new \Exception('Override button not found for ' . $userType . '=>' . $name);
        }
    }

    private function iOverrideApprovalForAsWithTheComment($page, $statusValue, $comment)
    {
        $this->getSession()->getDriver()->switchToIFrame('exp_approval_action_inner');
        $status = $page->findField('overwrite_action');
        if ($status) {
            $this->selectOption($statusValue, 'overwrite_action');
        }
        $commentField = $page->findField('comment');
        if ($commentField && (!(is_null($comment)))) {
            $this->fillField('comment', $comment);
        }
        $this->iClickToButton('Confirm');
    }

    /**
     * @When /^I should (not )?see "([^"]*)" filter$/
     */
    public function iShouldSeeFilter($not, $filterlabel)
    {
        $page = $this->getSession()->getPage();
        $filterlabel = $this->translate(trim($filterlabel));
        if ($this->getActiveInterface() === 'students') {
            $xpath = "//div[@class='search-form-filters']/descendant::*[normalize-space(text())='$filterlabel']";
        } else {
            $xpath = "//form[@name='search_filters']/descendant::*[normalize-space(text())='$filterlabel']";
        }
        if (($page->find('xpath', $xpath)) xor $not) {
            return true;
        }
        throw new \Exception('filter ' . $filterlabel . ($not ? ' is' : ' is not') . ' found');
    }

    private function findElementId($field)
    {
        $page = $this->getSession()->getPage();
        $field = $this->translate($field);
        $xpath = "//*[contains(@id, 'dnf_class_values_') and (@title = \"$field\" or @js_required_field = \"$field\")]";
        $element = $page->find('xpath', $xpath);
        if (!$element) {
            $label = $page->find('xpath', "//label[text()=\"$field\"]");
            if ($label !== null && $label->hasAttribute('for')) {
                $id = $label->getAttribute('for');
                $element = $page->find('xpath', "//select[starts-with(@id,'$id')]");
            }
            if (!$element) {
                $xpath = "//td[text()=\"$field\"]/following-sibling::td/input[not(@type = 'checkbox')]";
                $element = $page->find('xpath', $xpath);
                if (!$element && strpos($field, '_') !== false) {
                    [$label, $minMax] = explode('_', $field);
                    $xpath = "//td[text()=\"$label\"]/following-sibling::td/input[contains(@id,\"$minMax\")]";
                    $element = $page->find('xpath', $xpath);
                }
            }
        }
        if ($element) {
            return $element->getAttribute('id');
        }
    }

    /**
     * @Then I fill fields
     */
    public function iFillFields(TableNode $table, $array_key = '')
    {
        static $translatedValues = [
            'AR-CSM' => [
                'Senior' => 'طالب بالصف النهائي',
                'Donuts and Coffee [85.00]' => 'Donuts and Coffee [٨٥٫٠٠ ر.س.]'
            ],
            'ES-CSM' => [
                'Donuts and Coffee [85.00]' => 'Donuts and Coffee [EUR 85.00]'
            ],
            'PT-CSM' => [
                'Donuts and Coffee [85.00]' => 'Donuts and Coffee [€ 85,00]'
            ]
        ];
        static $arabicTestFields = [
            "filters[class]" => "dnf_class_values[eventlog][class]",
            "filters[label]" => "dnf_class_values[eventlog][label]"
        ];
        static $fieldsInLaw = [
            'Activate Counseling Appointment Requests' => 'Enable Counseling Appointment Requests',
            'Specify Maximum Number of Counseling Appointments' => 'Limit Student Counseling Appointments',
            'Show Pending Counseling Slots To Students' => 'Show Requested Counseling Slots To Students',
            'Allow Students to Cancel Appointments' => 'Allow Students to Cancel Counseling Appointments'
        ];
        $i = 0;
        $page = $this->getSession()->getPage();
        $activeInterface = $this->getActiveInterface();
        $currentUrl = $this->getSession()->getCurrentUrl();
        foreach ($table->getHash() as $record) {
            if ($i > 0) {
                break;
            }
            if (isset($record['setting'])) {
                $table_new = $table->getHash();
                $record = $table_new[$array_key];
                $field = $record['setting'];
                ++$i;
            } else {
                $field = $record['field'];
            }
            if (strpos($field, "\s") !== false) {
                $field = str_replace('\s', ' ', $field);
            }
            $optional = false;
            if (isset($record['optional'])) {
                $optional = $record['optional'];
                if ($optional === 'id') {
                    $field = $this->findElementId($field);
                } elseif ($optional === 'scroll') {
                    $this->scrollToElement("//div[contains(@class,'fieldgroup')]//span[contains(text(),'$field')]");
                }
            }
            if ($optional === 'full-law' && self::$tag === 'LAW-CSM' && isset($fieldsInLaw[$field])) {
                $field = $fieldsInLaw[$field];
            }
            $value = $record['value'];
            switch ($record['type']) {
                case "autocomplete":
                case "autocomplete_input":
                    $this->iSelectWith($field, $value, $optional);
                    break;
                case "multi":
                    $this->iPickMajor2($value, $field, $optional);
                    break;
                case "multiselect":
                    $this->additionallySelectOption($field, $value);
                    break;
                case "radio":
                    if ($field === 'Nationwide' && self::$tag === 'LAW-CSM') {
                        $addLocationButton = $page->find('xpath', "//input[@value='Add New Location']");
                        if ($addLocationButton) {
                            $this->iClickToButton("Add New Location");
                        }
                    }
                    if ($optional === 'student_profile') {
                        $radioElement = $page->find('xpath', "//div[contains(@class, 'ng-untouched')]/div[normalize-space(text())='$field']/following-sibling::div/label[normalize-space(text())='$value']");
                        if ($radioElement) {
                            $id = $radioElement->getAttribute('for');
                            $this->clickXpath("//input[@id='$id' and @type='radio']");
                        }     
                    } elseif (($field !== 'Restrict Applications' || self::$tag !== 'LAW-CSM') && $field !== 'Multi Office Contact') {
                        if (($field !== 'Featured Job For Faculty' || self::$tag !== 'MODULAR-CSM') && $field !== 'Multi Office Contact') {
                            $this->iCheckRadioButton2($field, $value);
                        }
                    }
                    if ($field === 'Multi Office Contact') {
                        $translatedfield1 = $this->translate($field);
                        $translatedfield2 = $this->translate('Multi-office Contact');
                        $translatedvalue = $this->translate($value);
                        $label = $page->find('xpath',
                            "//*[text()='$translatedfield1']/following::input[@alt='$translatedfield1 $translatedvalue']|//*[text()='$translatedfield2']/following::input[@alt='$translatedfield2 $translatedvalue']");
                        if ($label) {
                            $label->focus();
                            $label->click();
                        }
                    }
                    break;
                case "select":
                    if (isset($translatedValues[self::$tag][$value])) {
                        $value = $translatedValues[self::$tag][$value];
                    }
                    if (isset($arabicTestFields[$field])
                        && (strpos($currentUrl, 'ar-interprise-csm.test') !== false)) {
                        $eventLog = $page->find('xpath', "//h1[text()='" . $this->translate('Event Log') . "']");
                        if ($eventLog) {
                            $field = $arabicTestFields[$field];
                        }
                    }
                    if ($field === 'Catering Options' && $this->locale !== 'en') {
                        $field = $this->translate($field);
                        $id = $this->getIdFromLabelElement($field);
                        $options = $page->findAll('xpath', "//select[@id='$id']/option");
                        foreach ($options as $option) {
                            if ($value === $option->gettext()) {
                                $value = (int) $option->getAttribute('value');
                                break;
                            }
                        }
                        $this->selectOption($value, $field);
                    }
                    if ($field === 'School Affiliations' || $field === 'School Affiliation(s)') {
                        if (self::$tag === 'FULL-CSM') {
                            if ($optional === 'others') {
                                $this->selectOption('SANITY-MSE', $field);
                            } else {
                                $this->selectOption('SANITY', $field);
                            }
                        } elseif (self::$tag === 'MSE-CSM') {
                            if ($optional === 'others') {
                                $this->selectOption('SANITY', $field);
                            } else {
                                $this->selectOption('SANITY-MSE', $field);
                            }
                        }
                    } elseif ($field === 'State/Province') {
                        $label = $page->find('xpath',
                            "//select[@js_required_field='" . $this->translate('State') . "' or @js_required_field='$field']");
                        if ($label) {
                            $label->selectOption($value);
                        }
                    } elseif ($field === 'Country') {
                        $value = $this->translateCountry($value, $this->locale);
                        $this->selectOption($value, $field);
                    } elseif ($field === 'Mile') {
                        $this->clickXpath("//*[@id='location-distance-btn']");
                        if ($optional && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                            $value = $optional;
                        }
                        $this->iClickToButton($value);
                    } elseif ($field === 'Event Type' && self::$tag === 'PT-CSM' && strpos($value,
                        'Career Fair') !== false) {
                        $field = $this->translate($field, 'form career_fair_edit');
                        $this->selectOption($value, $field);
                    } elseif ($field === 'Time Slot' && self::$tag === 'PT-CSM') {
                        $field = $this->translate($field, 'form rsvp_manager');
                        $this->selectOption($value, $field);
                    } elseif ($field === 'emprank') {
                        $rankEl = $this->findXpathElement("//div[@class='list-item-title']/a[text()='$optional']");
                        $infoString = $rankEl->getAttribute('href');
                        $hrefParams = explode("&", $infoString);
                        $rezid = str_replace('rezid=', '', $hrefParams[2]);
                        $this->clickXpath("//*[contains(@id,'emprank_" . $rezid . "') or contains(@name,'emprank_" . $rezid . "')]/option[@value='" . trim($value) . "']");
                    } elseif ($field === 'Annual Base Salary' || $field === 'Signing Bonus') {
                        $field = $this->translate($field);
                        $this->clickXpath("//label[text()='$field']/../following-sibling::div/select/option[@value='$value']");
                    } elseif ($optional === 'picklist') {
                        $picklist = $this->findXpathElement("//td[@hp_val='$field']/following::select[1]", 10);
                        $this->selectOption($value, $picklist->getAttribute('name'));
                    } elseif (in_array($field, ['Default Kiosk for Scheduled Virtual Counseling Appointments', 'Set Default Counseling Appointment Length'])) {
                        $this->clickXpath("//label[text()='$field']/../following-sibling::div/select/option[text()='$value']");
                    } elseif ($field === 'Type' && is_numeric($optional)) {
                        $id = $this->fillResumeSections($field, $optional);
                        $this->selectOption($value, $id);
                    } elseif ($field === "Accommodate/Access Case") {
                        $field = "toolbar_student_accommodate_accessibility___";
                        $this->selectOption($value, $field);
                    } elseif ($field === "Advocate Incident Case History") {
                        $field = "toolbar_student_advocate_incident_history___";
                        $this->selectOption($value, $field);
                    } elseif ($field === "Advocate CARE Case History") {
                        $field = "toolbar_student_advocate_care_history___";
                        $this->selectOption($value, $field);
                    } elseif ($field === 'Time Slot' && $activeInterface === 'students') {
                        $this->clickXpath("//div[normalize-space(text()='$field')]/following::div[@class='field-widget'][3]/select[@name='time_slot']/option[text()='$value']");
                    } elseif ($field === 'Employer Status' && $activeInterface === 'employers') {
                        $field = "empstatus";
                        $this->clickXpath("//select[contains(@id,'$field')]/option[text()='" . $this->translate($value) . "']");
                    } else {
                        $this->selectOption($value, $field);
                    }
                    break;
                case "checkbox":
                    if ($optional === 'uncheck' || (strpos($optional, 'uncheck-rights') !== false)) {
                        $this->iunCheckbox($value, $field, $optional);
                    } else {
                        $this->iCheckbox($value, $field, $optional ?: '');
                    }
                    break;
                case "richtext":
                    if (strpos($optional, 'Representative') !== false) {
                        [$title, $name] = explode("__", $optional);
                        $xpath = "//*[text()='$name']/parent::div//label[text()='$field']/../descendant::textarea";
                        $id = $page->find('xpath', $xpath)->getAttribute('id');
                        if ($id) {
                            $this->getSession()->executeScript("tinymce.get('$id').setContent('$value');");
                            $this->clickXpath("//*[text()='$name']/parent::div//label[text()='$field']/../descendant::i[@class='mce-ico mce-i-bold']");
                        } else {
                            throw new \Exception("Could not find $field");
                        }
                    } elseif ($optional === 'tinymce') {
                        $xpath = "//label[normalize-space(text())='$field']";
                        $id = $page->find('xpath', $xpath)->getAttribute('for');
                        if ($id) {
                            $this->getSession()->executeScript("tinymce.get('$id').setContent('$value');");
                            $this->clickXpath("//label[normalize-space(text())='$field']/../following::i[@class='mce-ico mce-i-bold'][1]");
                        } else {
                            throw new \Exception("Could not find timymce $field");
                        }
                    } elseif (strpos($optional, 'groupchat') !== false) {
                        $xpath = "//label[text()='$field']/../descendant::textarea";
                        $id = $page->find('xpath', $xpath)->getAttribute('id');
                        if ($id) {
                            $this->getSession()->executeScript("tinymce.get('$id').setContent('$value');");
                            $this->clickXpath("//label[text()='$field']/../descendant::i[@class='mce-ico mce-i-bold']");
                        } else {
                            throw new \Exception("Could not find $field");
                        }
                    } else {
                        $this->iEnterValueIntoRichtextField($value, $field);
                    }
                    break;
                case "angular-select":
                    $field = $this->translate($field);
                    $value = $this->translate($value);
                    $xpath = "//*[contains(text(),'$field')]/following::div[contains(@class,'widget search_filter_multiselect')]";
                    $element = $page->find('xpath', $xpath);
                    if ($element) {
                        $element->focus();
                        $element->click();
                        $value = trim($value);
                        $this->clickXpath("//*[contains(text(),'$field')]/parent::div/following::div[1]//*[normalize-space(text())='$value']");
                    }
                    if (!$element) {
                        $xpath = "//*[contains(text(),'$field')]/select";
                        $element = $page->find('xpath', $xpath);
                        if ($element) {
                            $element->selectOption($value);
                        }
                    }
                    if (!$element) {
                        $xpath = "//form-hierpicklist//label[contains(text(),'$field')]/../..//div[@class='field-widget']/div[@class='picklist-container input-text']";
                        $element = $page->find('xpath', $xpath);
                        if ($element) {
                            $element->click();
                            $clearDropDown = $page->find('xpath', "//form-hierpicklist//label[contains(text(),'$field')]/../..//div[@class='field-widget']/div[2]//button[@aria-label='Clear']");
                            if ($clearDropDown) {
                                $clearDropDown->click();
                            }
                            $this->clickXpath("//div[@class='picklist-item-label-wrapper']/label[normalize-space(text())='$value']");
                        }
                    }
                    if (!$element) {
                        throw new \Exception("Could not select '$value' in '$field' angular-select");
                    }
                    break;
                case "quilltext":
                    $label = $page->find('xpath', "//*[contains(@class,'ql-editor')]");
                    if ($label) {
                        $function = <<<JS
                                (function(){
                                    document.querySelector("div.ql-editor").innerHTML = '$value';
                                })()
JS;
                        $this->getSession()->executeScript($function);
                    }
                    break;
                case "text":
                    if ($field === 'Password' && self::$tag === 'AR-CSM') {
                        $contactAccount = $this->translate('Contact: Peer only Mentors');
                        $contactPage = $page->find('xpath', "//*[text()='$contactAccount']");
                        if ($contactPage) {
                            $field = $this->translate($field, 'form contact_account');
                        }
                    }
                    if ($value === '546.25' && in_array(self::$tag, ['PT-CSM', 'AR-CSM'])) {
                        $value = '546,25';
                    }
                    if ($optional === 'unique') {
                        $value .= '_' . time();
                        if ($field === 'Event Nickname' && self::$tag === 'AR-CSM') {
                            $field = $page->find('xpath', "//input[contains(@id, 'nickname')]");
                            $id = $field->getAttribute('id');
                            $this->fillField($id, $value);
                        } else {
                            $this->fillField($this->translate($field), $value);
                        }
                    } elseif ($field === 'Street Address') {
                        $text1 = $this->translate('Street');
                        $text2 = $this->translate('street');
                        $label = $page->find('xpath',
                            "//textarea[@js_required_field=$text1 or @js_required_field='" . $this->translate($field) . "' or contains(@id,$text2)]");
                        if ($label) {
                            $elementId = $label->getAttribute('id');
                            $this->fillField($elementId, $value);
                        }
                    } elseif (in_array($field, ['Zip Code/Postal Code', 'Zip Code'])) {
                        $label = $page->find('xpath',
                            "//input[@js_required_field='Zip' or @js_required_field='$field' or contains(@name,'zip') or contains(@id,'zip')]");
                        if ($label) {
                            $elementId = $label->getAttribute('id');
                            $this->fillField($elementId, $value);
                        }
                    } elseif ($optional === 'invalid') {
                        $this->fillField($field, $value);
                        $this->iWait(2000);
                        $this->postValueToXpath("//*[@id='$field']", $value);
                    } elseif (strpos($optional, 'Representative') !== false) {
                        if ($field === 'Full Name') {
                            $xpath = "//label[text()='$field']/parent::form-field/div/input[@type='text' and contains(@class,'ng-empty')]";
                        } else {
                            [$title, $name] = explode("__", $optional);
                            $xpath = "//*[text()='$name']/parent::div/descendant::label[text()='$field']/parent::form-field/div/input[@type='text' or @type='email']";
                        }
                        $this->postValueToXpath($xpath, $value);
                    } elseif ($optional === 'picklist') {
                        $xpath = "//div[@aria-label='$field']/input[@type='text']";
                        $element = $page->find('xpath', $xpath);
                        if (!$element) {
                            $xpath = "//div[@aria-label='" . ucfirst($field) . "']/input[@type='text']";
                            $element = $this->findXpathElement($xpath);
                        }
                        $id = $element->getAttribute('id');
                        echo $id;
                        $this->fillField($id, $value);
                    } elseif ($optional === 'angular') {
                        $selector = "md-dialog textarea[name='$field']";
                        $field = $page->find('css', $selector);
                        if ($field === null) {
                            throw new \Exception("Field $field not found ");
                        }
                        $field->setvalue($value);
                    } elseif ($field === 'Section Title' && is_numeric($optional)) {
                        $id = $this->fillResumeSections($field, $optional);
                        $this->fillField($id, $value);
                    } elseif ($field === 'Externship_min' || $field === 'Externship_max') {
                        if ($field === 'Externship_min') {
                            $selector = "//table[contains(@id, 'min_picklist')]/tbody/tr/td[text()[normalize-space()='Externship']]/following-sibling::td/input";
                        } else {
                            $selector = "//table[contains(@id, 'max_picklist')]/tbody/tr/td[text()[normalize-space()='Externship']]/following-sibling::td/input";
                        }
                        $field = $page->find('xpath', $selector);
                        if ($field === null) {
                            throw new \Exception("Field $selector not found ");
                        }
                        $field->setvalue($value);
                    } elseif ($optional === 'student_profile') {
                        $xpath = $page->find('xpath', "//div[contains(@class, 'ng-dirty')]/div/label[normalize-space(text())='$field']/parent::div/following-sibling::div/div/input[contains(@class, 'ng-untouched')][@type='text' or @type='url']");
                        if ($field === null) {
                            throw new \Exception("Field $field not found ");
                        }
                        $xpath->setvalue($value);
                    } else {
                        try {
                            if ($field === 'Save Report As') {
                                $label = $this->findXpathElement("//input[@alt='Save Report As']");
                                $elementId = $label->getAttribute('name');
                                $this->fillField($elementId, $value);
                            } else {
                                $this->fillField($this->translate($field), $value);
                            }
                        }  catch (\Exception $e) {
                            try {
                                if ($field === 'Salary Level (Legacy)') {
                                    $getString = strrchr($field, " ");
                                    $label = $this->findXpathElement(".//label[text()='" . $this->translate("Salary Level") . "$getString']/following::input[1]");
                                } else {
                                $label = $this->findXpathElement(".//label[text()='" . $this->translate($field) . "']/following::input[1]");
                                }
                                $elementId = $label->getAttribute('name');
                                $this->fillField($elementId, $value);
                            } catch (\Exception $e) {
                                $label = '';
                                if ($field === 'Subject' && self::$tag === 'ES-CSM' && $activeInterface === 'manager') {
                                    $label = $page->find('xpath', ".//label[text()='" . $this->translate($field, 'form contact_notes_default') . "']/following::input[1]");
                                }
                                if ($label) {
                                    $elementId = $label->getAttribute('name');
                                    $this->fillField($elementId, $value);
                                } elseif (in_array($field, ['Search', 'Search for Columns'])) {
                                    $xpath = "//input[@aria-label='$field' and @type='text']";
                                    if ($page->find('xpath', $xpath)) {
                                        $this->postValueToXpath($xpath, $value);
                                    }
                                } else {
                                    throw new \Exception("xpath: //label[text()='" . $this->translate($field) . "']/following::input[1] not found");
                                }
                            }
                        }
                    }
                    break;
                case "calendar":
                case "date":
                case "datetime":
                    $this->iFillInFormDate($optional, $field, $value, $record['type']);
                    break;
                case "rating":
                    $field = trim($field);
                    $xpath = "//form-rating/div/div[normalize-space(text())='$field']/../descendant::*[text()='$value']/parent::label | //label[text()='$field']/../following::div[contains(@class, 'star_ratings')]/../descendant::*[text()='$value']/parent::label";
                    $element = $page->find('xpath', $xpath);
                    if ($element) {
                        $element->click();
                    } else {
                        throw new \Exception("Rating field $field of $value was not found.");
                    }
                    break;
                default:
                    $field = $this->translate($field);
                    $fieldObject = $page->findField($field);
                    if (null === $fieldObject) {
                        throw new \Exception("Field $field ({$record['type']}) was not found.");
                    }
                    $field->selectOption($value);
            }
        }
    }

    protected function getIdFromLabelElement(string $field)
    {
        $labelElem = $this->findXpathElement("//label[text()='$field']");
        if ($labelElem) {
            return $labelElem->getAttribute('for');
        }
    }

    /**
     * @Then I update :tabs system settings:
     */
    public function iUpdateSystemSetting($tabs, TableNode $table)
    {
        $previous_section = null;
        $previous_tab = null;
        try {
            $this->iNavigateTo("More>Tools>System Settings");
        } catch (\Exception $e) {
            $this->iNavigateTo("Tools>System Settings");
        }
        $section = [];
        $tab = [];
        foreach ($table->getHash() as $i => $record) {
            if (($tabs !== "following") && $i < 1) {
                $navMenus = explode(">", $tabs);
                if (count($navMenus) > 1) {
                    $this->iNavigateToSettings($navMenus[0], $navMenus[1]);
                }
            } elseif (isset($record['section'], $record['tab'])) {
                $section[$i] = $record['section'];
                $tab[$i] = $record['tab'];
                $this->activeSection = ($section[$i] === $previous_section);
                $this->activeTab = ($tab[$i] === $previous_tab && $section[$i] === $previous_section);
                if ($i > 0 && !$this->activeTab) {
                    $this->iClickAndSee("Save Selections", "Your updates have been saved.");
                }
                $this->iNavigateToSettings($section[$i], $tab[$i]);
            }
            $this->iFillFields($table, $i);
            if (isset($record['save']) && $record['save'] === 'yes') {
                $this->iClickAndSee("Save Selections", "Your updates have been saved.");
            }
            if ($tabs === "following") {
                $previous_section = $section[$i];
                $previous_tab = $tab[$i];
            }
        }
        $this->iClickAndSee("Save Selections", "Your updates have been saved.");
        $this->activeSection = false;
        $this->activeTab = false;
    }

    /**
     * @Given /^I should (not )?see "([^"]*)" setting is "([^"]*)"$/
     */
    public function iShouldSeeSettingIs($not, $setting, $value)
    {
        $page = $this->getSession()->getPage();
        $settingExist = $page->find('xpath', "//label[text()='$setting']/../following-sibling::div/label[normalize-space(text())='$value']");
        if ($settingExist) {
            $id = $settingExist->getAttribute('for');
            $checked = $page->find('css', "input[checked]#$id");
            if (!$checked xor $not) {
                throw new \Exception('system setting ' . $setting . ($not ? ' is turned ' : ' is not turned ') . $value);
            }
        }
    }

    /**
     * @Then I click :button and see :text
     */
    public function iClickAndSee($button, $text)
    {
        $this->iClickToButton($button);
        $this->iWillSee($text);
    }

    /**
     * @Then I click :button and not see :text
     */
    public function iClickAndNotSee($button, $text)
    {
        $this->iClickToButton($button);
        $this->iWillNotSee($text);
    }

    /**
     * @Then I add picklist :value under :field
     */
    public function iAddPicklist($value, $field)
    {
        $page = $this->getSession()->getPage();
        $this->iNavigateTo("More>Tools>Picklists");
        $this->iSearchFor($field);
        $this->iWillSee($field);
        $this->iClickLink($field);
        $this->iClickToButton("Add a pick");
        $element = $page->find('xpath', "//td[@hp_new='New Item']//input[@id]");
        if ($element) {
            $elementId = $element->getAttribute('id');
            $this->fillField($elementId, $value);
            $this->review = 2;
            $this->picklist = $field;
            $this->newPick = $value;
        }
    }

    /**
     * @Then I click Choose to make a selection
     */
    public function iOpenChooseSelector()
    {
        $this->iClickToButton("ng-select-container");
    }

    public function iOpenJobForm()
    {
        $this->iClickToButton("Post a Job");
        try {
            $buttonLabel = $this->translate('Post to This');
            $this->clickXpath("//div[contains(text(),'$buttonLabel')]");
        } catch (\Exception $e) {
            //probably not a onestop school
        }
    }

    /**
     * @Then I post job with
     */
    public function iPostJobWith(TableNode $table)
    {
        $this->iOpenJobForm();
        $this->iFillFields($table);
    }

    /**
     * @When I fill ReportType :type
     */
    public function iFillReportType($type)
    {
        $translatedType = $this->translate($type);
        if (preg_match('/\bEditable\b/', $type) || preg_match('/\bCategory\b/', $type) || preg_match('/\bVisible\b/',
            $type)) {
            $splitType = explode(':', $type);
            $translatedType = $this->translate($splitType[1]);
            $typeVal = $this->translate($splitType[0]);
            $this->getSession()->executeScript('window.scrollTo(0,500);');
            $this->scrollToElement("//*[text()='$typeVal']/following::div[1]");
            $this->clickXpath("//*[text()='$typeVal']/following::div[1]");
            $element = $this->getSession()->getPage()->find('xpath', "//*[text()='$typeVal']/following::input[1]");
            $this->iWait(2000);
        } else {
            $element = $this->getSession()->getPage()->find('xpath',
                "//*[@id='main_content']/div/form/div/section/div/div/div[2]/div/div/input");
        }
        $element->setValue($translatedType);
        $element->blur();
        if ($type === 'Job' && self::$tag === 'LAW-CSM') {
            $xpath = "//section/div/div/div[2]/div/ul/li[text()='Student ']/preceding::li[1]";
        } elseif ($type === 'Workshop' && self::$tag === 'MODULAR-CSM') {
            $this->iWillSee("Workshop");
            $xpath = "//section/div/div/div[2]/div//ul[@class='chosen-results']//li[not(text()='Survey Response: ')]";
        } elseif ($type === 'OCI Bid' && self::$tag === 'LAW-CSM') {
            $xpath = "//section/div/div/div/div/ul/li[2]/em[text()='OCI Bid']";
        } else {
            $xpath = "//div/ul/li/em[text()='$translatedType']";
        }
        $this->iWait(2000);
        $this->clickXpath($xpath);
    }

    /**
     * @When I can display empty results in summaries for :type
     */
    public function iDisplayEmptyResults($type)
    {
        $this->iClickToButton("Show summary table");
        $this->iClickToButton("Show empty results");
        $element = "table#" . $this->underscore($type) . "-counts tr:last-child td:last-child";
        $this->assertElementContainsText($element, '0');
    }

    /**
     * @Then I navigate to :tab :subtab settings
     */
    public function iNavigateToSettings($tab, $subtab)
    {
        $tab = $this->translate($tab);
        if ($subtab === 'Career Fair' && self::$tag === 'PT-CSM') {
            $subtab = $this->translate($subtab, 'form CFRSVPForm');
        } else {
            $subtab = $this->translate($subtab);
        }
        if (!$this->activeTab) {
            if (!$this->activeSection) {
                $this->clickXpath("//td/a[@title='$tab']");
            }
            $this->clickXpath("//td/a[@title='$subtab']");
        }
        if (!$this->inAfterScenario) {
            $this->navigatedToSettings = true;
            $this->settingsNav[] = [$tab, $subtab];
        }
    }

    /**
     * @Then I navigate to :tab tab
     */
    public function iNavigateToTab($tabs)
    {
        $split = explode('>', $tabs);
        $subtab = "subtab";
        $size = count($split);
        if ($split[0]) {
            $this->iOpenTab($split[0]);
            if ($split[1] < $size) {
                $split[0] = $this->translate($split[0]);
                $tabName = lcfirst($split[1]);
                $nextTab = "//td/a[@title='" . $split[0] . "']/following::td/a[@href='?$subtab=" . $tabName . "']";
                $this->clickXpath($nextTab);
                $subtab = "sub" . $subtab;
                $checkcount = 2;
                while (isset($split[$checkcount]) && $split[$checkcount] < $size) {
                    $tabNameN = lcfirst($split[$checkcount]);
                    $appendXpath = "/following::td/a[@href='?" . $subtab . "=" . $tabNameN . "']";
                    $newXpath = $nextTab . $appendXpath;
                    $this->clickXpath($newXpath);
                    $subtab = "sub" . $subtab;
                    ++$checkcount;
                }
            }
        }
    }

    /**
     * @Then I check :label radio button :value
     */
    public function iCheckRadioButton2($label, $value)
    {
        $page = $this->getSession()->getPage();
        $translatedLabel = $this->translate($label);
        $label = trim($label);
        if (($label === 'Accept Registrations' || $label === 'Visible to Students') && self::$tag === 'PT-CSM') {
            $translatedLabel = $this->translate($label, 'form career_fair_edit');
        } elseif ($label === 'Slot Length' && self::$tag === 'ES-CSM') {
            $translatedLabel = $this->translate($label, 'form workshopForm');
        } else {
            $translatedLabel = trim($translatedLabel);
        }
        $intl_site = $this->translate('System Settings');
        if ($value === 'on' && (($intl_site) && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM']))) {
            $value = $this->translate($value, 'misc widget');
        } else {
            $value = $this->translate($value);
        }
        $ocr_check = $page->find('xpath', "//*[text()='" . $this->translate('Interview Time') . "']");
        if ($ocr_check && $label === 'Interview Time' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $translatedLabel1 = "Interview Time ";
        } else {
            $translatedLabel1 = $translatedLabel;
        }
        if (strpos($label, '>')) {
            $sectionAndField = explode('>', $label);
            $sectionEl = $this->findXpathElement("//span[text()='{$sectionAndField[0]}']", 10);
            $sectionId = $sectionEl->getAttribute('id');
            $fieldEl = $page->find(
                'xpath',
                "//span[text()='{$sectionAndField[1]}' and contains(@id,'$sectionId')]"
            );
            if ($fieldEl) {
                $fieldId = $fieldEl->getAttribute('id');
            } else {
                throw new \Exception("Could not find $sectionAndField field id for $sectionId");
            }
            $label = $sectionAndField[2];
            $xpath = "//*[contains(@id, '$fieldId')]//descendant::input[(@alt=' " . $value . "' or @alt=\"$label $value\" or @alt=\"$label: $value\")]";
        } elseif (strpos($label, '"')) {
            $xpath = "//*[text()='$label']/following::input[@alt='$value' or @alt='$label $value'][1]";
        } elseif ($label === 'Interview Time') {
            $xpathElement = null;
            $xpath = "//*[normalize-space(text())=\"$translatedLabel\"]/following::input[@alt=\" $value\" or @alt=\"$label $value\" or @alt=\"$translatedLabel1 $value\" or @title=\"$label $value\" or @title=\"$translatedLabel1 $value\"][2]";
            $xpathElement = $page->find('xpath', $xpath);
            if (!$xpathElement) {
                $xpath = "//*[normalize-space(text())=\"$translatedLabel\"]/following::input[@alt=\" $value\" or @alt=\"$label $value\" or @alt=\"$translatedLabel1 $value\" or @title=\"$label $value\" or @title=\"$translatedLabel1 $value\"][1]";
            }
        } else {
            $xpath = "//*[normalize-space(text())=\"$translatedLabel\"]/following::input[@alt=\" $value\" or @alt=\"$label $value\" or @alt=\"$translatedLabel1 $value\" or @title=\"$label $value\" or @title=\"$translatedLabel1 $value\"][1]";
        }
        $element = $page->find('xpath', $xpath);
        try {
            if ($element) {
                if ($element->isVisible() && !$element->hasAttribute('checked')) {
                    if ($this->navigatedToSettings) {
                        $defaultXpath = "//*[text()=\"" . $this->translate($label) . "\"]/following::input[@checked]";
                        $defaultEl = $page->find('xpath', $defaultXpath);
                        if ($defaultEl) {
                            $defaultValue = trim($defaultEl->getAttribute('alt'));
                            $this->changedSettings = true;
                            $this->modifiedSettings[] = [$label, $defaultValue];
                        }
                    }
                    $this->focusAndClick($xpath, $element);
                }
            } elseif (self::$tag === 'AR-CSM' && mb_strstr($translatedLabel, "استبعاد الوظائف التي تكون فيها خانة") !== false) {
                $this->clickXpath("//*[contains(text(),'استبعاد الوظائف التي تكون فيها خانة')]/following::input[@alt=' $value'][1] | //*[contains(text(),'استبعاد الوظائف التي تكون فيها خانة')]/following::label[text()='$value']/preceding-sibling::input[contains(@id,'sy_formfield_major_ignore_all')][1]");
            } else {
                $xpath = '//label[text()="' . $value . '"]/input[@name="' . $label . '"]';
                $element = $page->find('xpath', $xpath);
                if ($element) {
                    $this->focusAndClick($xpath, $element);
                } elseif (strpos($value, '_on_') !== false) {
                    [$action, $title] = explode('_on_', $value);
                    $this->clickXpath("//label[text()='" . $this->translate($title) . "']/following::td[contains(text(), '" . $this->translate($label) . "')][1]/following::input[@alt=' " . $this->translate($action) . "'][1]");
                } else {
                    $label = $this->translate($label);
                    $value = $this->translate(trim($value));
                    $element = $page->find('xpath', "//label[text()='$label']");
                    if ($element) {
                        $id = $element->getAttribute('for');
                        $this->clickXpath("//label[normalize-space(text())='$value']/input[@id='$id' and @type='radio']");
                    } else {
                        $label = trim($label);
                        $element = $page->find('xpath', "//form-radio/div/div[normalize-space(text())='$label']/../descendant::label[text()='$value']");
                        if ($element) {
                            $id = $element->getAttribute('for');
                            $this->clickXpath("//input[@id='$id' and @type='radio']");
                        } elseif ($label === 'Merge') {
                            $this->clickXpath("//input[@type='radio' and normalize-space(@alt)='$value']");
                        } else {
                            throw new \Exception("Could not find radio $label:$value ");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if ($element) {
                $this->getSession()->executeScript('window.scrollTo(0,500);');
                $element->click();
            } else {
                throw $e;
            }
        }
    }

    /**
     * @Given I navigate to :section
     */
    public function iNavigateTo($section)
    {
        $activeInterface = $this->getActiveInterface();
        $page = $this->getSession()->getPage();
        $navMenus = explode(">", $section);
        if ($activeInterface === 'manager') {
            $translatedHome = $this->translate('Home');
            $this->iClickLink($translatedHome);
        }
        if (($navMenus[0] !== 'User Menu') && (self::$tag !== 'AR-CSM')) {
            $this->iWillSee($navMenus[0]);
        }
        $revMenus = array_reverse($navMenus);
        $navCnt = count($navMenus);
        if ($navCnt > 1) {
            for ($i = 0; $i < $navCnt - 1; $i++) {
                $menu = $this->translate($navMenus[$i]);
                if ($navMenus[$i] === 'Job Postings' && $menu === 'إعلانات وظائف' && $activeInterface === 'manager') {
                    $menu = 'نشرات الوظيفة';
                }
                if ($navMenus[$i] === 'Employers' && self::$tag === 'AR-CSM' && $activeInterface !== 'students') {
                    $menu = 'أصحاب الأعمال';
                }
                if ($activeInterface === 'employers' && $navMenus[$i] === 'Account' && self::$tag === 'PT-CSM') {
                    $link = $page->find('xpath',
                        "//*[@id='nav-container' or @id='branch-top']//a/span[text()=' $menu']");
                } elseif ($activeInterface === 'students') {
                    if ($navMenus[$i] === 'User Menu') {
                        $link = $this->findXpathElement("//div[@id='user-avatar']//button[contains(@aria-label,'$menu')]", 500);
                    } elseif ($navMenus[$i] === 'Jobs') {
                        $this->visit($this->locatePath('/students/app/jobs/discover'));
                        if ($navMenus[$i + 1] === 'Search') {
                            return;
                        }
                    }
                } else {
                    $link = $page->find('xpath',
                        "//*[@id='nav-container' or @id='branch-top']//a[@title='$menu']");
                }
                if ($link) {
                    $link->focus();
                    $link->click();
                    try {
                        if ($link->hasAttribute('aria-expanded') && $link->getAttribute('aria-expanded') === 'false') {
                            $link->click();
                        } else {
                            break;
                        }
                    } catch (\Exception $e) {
                       // no attribute to find or expand
                       break;
                    }
                }
            }
        } elseif (($activeInterface === 'students') && in_array($navMenus[0], ['Resources', 'Events', 'Employers'])) {
            $this->visit($this->locatePath('/students/app/' . strtolower($navMenus[0])));
            return;
        }
        if (isset($navMenus[1]) && $navMenus[1] === 'Reporting' && $activeInterface === 'manager' && self::$tag === 'PT-CSM') {
            $reporting = $this->translate($navMenus[1], 'nav manager');
            $this->clickXpath("//li/a[not(@rel) and @title='$reporting']");
            $this->clickXpath("//li/a[@rel and @title='$reporting']");
        } elseif (isset($navMenus[1]) && $navMenus[1] === 'Publication Requests' && $activeInterface === 'employers') {
            $this->clickXpath("//span[(normalize-space(text())='" . $this->translate($navMenus[0])."')]/following::div[contains(@class, 'nav-level')][1]/ul/li/a/span[normalize-space(text())='" . $this->translate($navMenus[1])."']");
            return;
        } else {
            $section = $this->translate($navMenus[$navCnt - 1]);
            if ($navMenus[$navCnt - 1] === 'Job Postings' && $section === 'إعلانات وظائف' && $activeInterface === 'manager') {
                $section = 'نشرات الوظيفة';
            }
            if ($navMenus[$navCnt - 1] === 'Employers' && self::$tag === 'AR-CSM' && $activeInterface !== 'students') {
                $section = 'أصحاب الأعمال';
            }
            $xpath = "//*[@id='branch-top' or @id='user-tools' or @role='tablist']//a[(@title='$section') and not(contains(@href,'#') or contains(@href,'javascript:;'))]";
            $link = $page->find('xpath', $xpath);
            if ($link) {
                $this->focusAndClick($xpath, $link);
            } else {
                $xpath = "//*[@id='nav-container']//a[not(contains(@href,'javascript:;'))]/span[(normalize-space(text())='$section')]";
                $link = $page->find('xpath', $xpath);
                if ($link) {
                    $this->focusAndClick($xpath, $link);
                } else {
                    $xpath = "//*[@id='nav-container' or @id='branch-top' or @id='user-tools' or @role='tablist' or @class='topnav' or contains(@class,'topnav ng-tns')]//a[(normalize-space(text())='$section' or @title='$section') and not(contains(@href,'#') or contains(@href,'javascript:;'))]";
                    if (($navMenus[0] === 'User Menu') && ($activeInterface === 'students') && ($navMenus[1] !== 'Help & Feedback')) {
                        $this->iWillSee($section);
                    }
                    $link = $page->find('xpath', $xpath);
                    if ($link) {
                        $this->focusAndClick($xpath, $link);
                    } else {
                        throw new \Exception("Could not find $section");
                    }
                }
            }
            if ($navCnt > 1) {
                for ($i = 1; $i < $navCnt; $i++) {
                    $rev = $this->translate($revMenus[$i]);
                    if ($revMenus[$i] === 'Job Postings' && $rev === 'إعلانات وظائف' && $activeInterface === 'manager') {
                        $rev = 'نشرات الوظيفة';
                    }
                    if ($navMenus[$i] === 'Employers' && self::$tag === 'AR-CSM' && $activeInterface !== 'students') {
                        $rev = 'أصحاب الأعمال';
                    }
                    if ($activeInterface === 'employers' && $revMenus[$i] === 'Account' && self::$tag === 'PT-CSM') {
                        $collapse = $page->find('xpath',
                            "//*[@id='nav-container' or @id='branch-top' or @id='user-tools']//a/span[text()=' $rev']");
                    } else {
                        $collapse = $page->find('xpath',
                            "//*[@id='nav-container' or @id='branch-top' or @id='user-tools']//a[@title='$rev']");
                    }
                    if ($collapse) {
                        try {
                            $collapse->click();
                            if ($collapse->hasAttribute('aria-expanded') && $collapse->getAttribute('aria-expanded') === 'true') {
                                $collapse->click();
                            }
                        } catch (\Exception $e) {
                            // do nothing, some menus can not be closed
                        }
                    }
                }
            }
        }
        if ($this->navigatedToSettings) {
            $this->navigatedToSettings = false;
        }
        $this->iWillSeeLoadingIndicator();
    }

    protected function findCheckbox($value, $label, $unTranslatedLabel = '')
    {
        $page = $this->getSession()->getPage();
        if ($unTranslatedLabel === 'User Rights' || $unTranslatedLabel === 'Rights') {
            $translateValue = $value;
            $xpath = $unTranslatedLabel . ": " . $translateValue;
        } else {
            $xpath = ($label === $this->translate('Save as')) ? ' ' . $label . ':' : $label . ': ' . $value;
        }
        $xpath = "//*[@alt=\"$xpath\" or @title=\"$xpath\" or @alt=\"$value\" or @data-text-value=\"$xpath\" or normalize-space(@data-text-value)=\"$label\"] | //input[@data-fid=\"$value\"] | //span[text()='$value']/input[@type='checkbox']";
        $field = $page->find('xpath', $xpath);
        if (!$field) {
            if (!$value) {
                if ($label === 'Desired Majors' && self::$tag === 'LAW-CSM') {
                    $label = 'desired practice areas';
                }
                $label = mb_strtolower($label);
                $xpath = "//li[@data-search='$label']//input";
                $field = $page->find('xpath', $xpath);
            } elseif (strpos($label, "_Slot") !== false) {
                $label_id = str_replace("_Slot", '', $label);
                $xpath = "//tr/td[contains(.,'$value')]//input[contains(@name,'$label_id')]";
                $field = $page->find('xpath', $xpath);
            } elseif (in_array($unTranslatedLabel, ['Job Screening Criteria', 'OCR Screening', 'Exp. Learning'])) {
                $field = $this->getOfCheckbox($value);
            } else {
                $xpath = "//input[@aria-label='$label" . ' ' . "$value'] | //input[@title='$label" . ' ' . "$value'] | //*[normalize-space()='$label']/following::div[@class='field-widget'][1]/input[@type='checkbox' and @value='$value']";
                $field = $page->find('xpath', $xpath);
            }
        }
        return $field;
    }

    public function translateCheckbox($value, $label)
    {
        if ($label !== 'OCR Screening' && $label !== 'Exp. Learning') {
            if ($value === 'Internship' && self::$tag === 'AR-CSM') {
                $value = "دريب صيفي";
            } else {
                $value = $this->translate($value);
            }
            $label = $this->translate($label);
        }
        return [$value, $label];
    }

    public function checkOption($option)
    {
        $translatedOption = $this->translate($option);
        parent::checkOption($translatedOption);
    }

    public function uncheckOption($option)
    {
        $translatedOption = $this->translate($option);
        parent::uncheckOption($translatedOption);
    }

    /**
     * @Given I check :value for :label
     */
    public function iCheckbox($value, $label, $optional = '')
    {
        if (self::$tag === 'LAW-CSM' && $value === 'Desired Majors' && ($label === 'Job' || $label === 'Summaries')) {
            $value = "Desired Practice Areas";
        }
        [$translatedValue, $translatedLabel] = $this->translateCheckbox($value, $label);
        $field = $this->findCheckbox($translatedValue, $translatedLabel, $label);
        if ($field) {
            try {
                $field->check();
            } catch (\Exception $e) {
                $this->getSession()->getDriver()->click($field->getXPath());
            }
        } elseif (strpos($optional, 'check-rights') !== false) {
            $field = $this->iCheckUserRights($label, $translatedValue, $optional);
            $field->check();
        } elseif ($optional === 'picklist') {
            $picklist = $this->iFindPicklist($label, $value);
            $picklist->click();
        } else {
            if ($label === 'Position Type' && self::$tag === 'ES-CSM') {
                $this->clickXpath("//*[text()=\"$translatedLabel\"]//following::label[contains(text(),\"$translatedValue\")]");
            } elseif ($label === 'Select this item') {
                $this->clickXpath("//div/a[text()='$value']/../../preceding-sibling::div[1]/input[@type='checkbox' and @alt='$translatedLabel']");
            } elseif ($label === 'Current') {
                $this->clickXpath("//*[text()='$label']/../preceding-sibling::div");
            } else {
                if ($optional === 'preferred') {
                    $translatedValue = date("M jS", strtotime($translatedValue));
                    $this->actualDate = $translatedValue;
                }
                try {
                    $this->clickXpath("//*[text()=\"$translatedLabel\"]//following::label[text()=\"$translatedValue\"] | //input[@value='" . mb_strtolower($translatedValue) . "']");
                } catch (\Exception $e) {
                    $this->clickXpath("//*[normalize-space()=\"$translatedLabel\"]//following::*[contains(normalize-space(text()),\"$translatedValue\")][1] | //input[@name='$translatedLabel' and @data-text-value='$translatedValue']");
                }
            }
        }
    }

    private function iFindPicklist($label, $value)
    {
        $page = $this->getSession()->getPage();
        $headers = $page->findAll('xpath', "//table[not(@role or contains(@class,'list_maincol'))]/tbody/tr[3]/td");
        if ($headers) {
            $key = 0;
            foreach ($headers as $key => $cellvalue) {
                if ($label === $cellvalue->getText()) {
                    break;
                }
            }
            $key = ($label === 'Pick Label') ? ('last()-1') : ($key + 1);
            $xpath = "//table[not(@role)]/tbody/tr/td[@hp_val='$value']/../td[$key]/input[@type='checkbox']";
            $element = $page->find('xpath', $xpath);
        } else {
            $element = $page->find('xpath', "//td[text()='$value']/preceding-sibling::td/input[@type='checkbox']");
            if (!$element) {
                $id = $this->getIdFromLabelElement($label);
                $element = $page->find('xpath', "//span[normalize-space()='$value']/input[@id='$id' and @type='checkbox']");
            }
        }
        if ($element) {
            return $element;
        }
        throw new \Exception("Field $label not found for $value");
    }

    /**
     * @Given I check activity :activity for :step
     */
    public function iCheckActivityFor($activity, $step)
    {
        $activityCheckbox = $this->findActivity($activity, $step);
        if (!$this->isActivityChecked($activityCheckbox)) {
            $activityCheckbox->click();
        }
    }

    /**
     * @Given I uncheck activity :activity for :step
     */
    public function iUncheckActivityFor($activity, $step)
    {
        $activityCheckbox = $this->findActivity($activity, $step);
        if ($this->isActivityChecked($activityCheckbox)) {
            $activityCheckbox->click();
        }
    }

    private function findActivity($activity, $step)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//*[normalize-space(text())='$step']/../following::div/label[normalize-space(text())='$activity']/preceding-sibling::input[@type='checkbox'] | //*[normalize-space(text())='$step']/../following::div[text()[normalize-space()='$activity']]/input[@type='checkbox'] | //*[normalize-space(text())='$step']/../following::div/span/label[normalize-space(text())='$activity']/preceding-sibling::input[@type='checkbox']";
        $activityExists = $page->find('xpath', $xpath);
        if ($activityExists) {
            return $activityExists;
        }
        throw new \Exception('Activity ' . $activity . ' not exist for ' . $step);
    }

    private function isActivityChecked($activityCheckbox)
    {
        $attribute = 'id';
        if ($activityCheckbox->hasAttribute('data-taskid')) {
            $attribute = 'data-taskid';
        }
        $id = $activityCheckbox->getAttribute($attribute);
        $selector = 'document.querySelector(`[' . $attribute . '="' . $id . '"]`).checked';
        try {
            $isChecked = $this->getSession()->evaluateScript('return ' . $selector);
        } catch (\Exception $e) {
            $isChecked = false;
        }
        return $isChecked;
    }

    /**
     * @Given I will see the activity :activity is enabled for :step
     */
    public function iWillSeeTheActivityIsEnabledFor($activity, $step)
    {
        $activityCheckbox = $this->findActivity($activity, $step);
        if ($activityCheckbox->hasAttribute('disabled')) {
            throw new \Exception($activity . ' is disabled');
        }
    }

    /**
     * @Given I will see the activity :arg1 is disabled for :arg2
     */
    public function iWillSeeTheActivityIsDisabledFor($activity, $step)
    {
        $activityCheckbox = $this->findActivity($activity, $step);
        if (!$activityCheckbox->hasAttribute('disabled')) {
            throw new \Exception($activity . ' is enabled');
        }
    }

    /**
     * @Given /^I will see the activity "([^"]*)" is (not )?checked for "([^"]*)"$/
     */
    public function iWillSeeActivityIsCheckedFor($activity, $not, $step)
    {
        $activityCheckbox = $this->findActivity($activity, $step);
        if (!$this->isActivityChecked($activityCheckbox) xor $not) {
            throw new \Exception($activity . ($not ? ' is' : ' is not') . ' checked');
        }
    }

    /**
     * @Given I uncheck :value for :label
     */
    public function iunCheckbox($value, $label, $optional = '')
    {
        [$translatedValue, $translatedLabel] = $this->translateCheckbox($value, $label);
        $field = $this->findCheckbox($translatedValue, $translatedLabel, $label);
        if ($field) {
            $field->uncheck();
        } elseif (strpos($optional, 'uncheck-rights') !== false) {
            $field = $this->iCheckUserRights($label, $translatedValue, $optional);
            $field->uncheck();
        } else {
            throw new \Exception("Could not find $translatedValue checkbox");
        }
    }

    public function iCheckUserRights($label, $value, $optional)
    {
        $addButton = "//div[@class='user_rights_container']//following::*[text()";
        if (strpos($label, ">") !== false && strpos($optional, 'expanded') === false) {
            $split = explode(">", $label);
            $cnt = count($split);
            for ($i = 0; $i < $cnt; $i++) {
                $this->clickXpath($addButton . "='" . $this->translate($split[$i]) . "']/preceding::td[1]");
            }
        } elseif (strpos($optional, 'expanded') === false) {
            $this->clickXpath($addButton . "='" . $this->translate($label) . "']/preceding::td[1]");
        }
        $field = $this->getSession()->getPage()->find('xpath', "//span[text()='$value']/input[@type='checkbox']");
        if ($field) {
            return $field;
        }
        throw new \Exception("Could not find //span[text()='$value']/input[@type='checkbox'] ");
    }

    /**
     * @Given I provide all rights excluding :removeRights to :student
     */
    public function iProvideAllRights($removeRights, $student)
    {
        $page = $this->getSession()->getPage();
        $this->iNavigateTo("Students>Students");
        $this->iSearchFor($student);
        $this->iWillSee($student);
        $this->iClickAndSee("Edit", "Student ID");
        $this->iOpenTab("Account");
        $this->iWillSee("User Rights");
        $element = $page->findAll('xpath',
            "//span[@class='checkboxgroup_default']//input[contains(@name,'rights')]/following::label[contains(@for,'rights')]");
        if ($element) {
            $uRights = $this->translate("User Rights");
            foreach ($element as $key => $right) {
                $rights[$key] = $right->getText();
                if (strpos($removeRights, $rights[$key]) !== false) {
                    $this->iunCheckbox($rights[$key], $uRights);
                } else {
                    $this->iCheckbox($rights[$key], $uRights);
                }
            }
        } else {
            throw new \Exception("Could not find user rights options");
        }
        $this->iClickToButton("save");
    }

    private function fillCustomTimeField(DateTime $dateTime, $fieldLabel)
    {
        $minuteInterval = 15;
        $values['hour'] = $dateTime->format('h');
        $values['min'] = floor($dateTime->format('i') / $minuteInterval) * $minuteInterval;
        $values['ampm'] = $dateTime->format('a');
        $values['date'] = $dateTime->format('Y-m-d');
        $values['time'] = $dateTime->setTime($dateTime->format('H'), $values['min'], 0)->format('Hi');
        $page = $this->getSession()->getPage();
        if ($fieldLabel === 'Set Message Send Time') {
            $timeZoneChange = $dateTime;
            $timeZoneChange->setTimeZone(new \DateTimeZone('America/New_York'));
            $values['date'] = $timeZoneChange->format('Y-m-d');
            $values['hour'] = $timeZoneChange->format('h');
            $values['min'] = floor($timeZoneChange->format('i') / $minuteInterval) * $minuteInterval;
            $values['ampm'] = $timeZoneChange->format('a');
            $minToSet = $values['min'];
            $searchXpath = "//input[@id='popupcal_send_time']";
            $this->postValueToXpath($searchXpath, $values['date']);
            $id = "send_time";
            if ($minToSet >= 30) {
                $this->selectOption("30", "min_" . $id);
            } elseif ($minToSet < 30) {
                $this->selectOption("00", "min_" . $id);
            }
            $this->selectOption($values['hour'], "hour_" . $id);
            $this->selectOption($values['ampm'], "ampm_" . $id);
            return true;
        }
        $id = $this->getIdFromLabelElement($fieldLabel);
        try {
            $this->selectOption($values['time'], 'list_' . $id);
        } catch (\Exception $e) {
            $dateField = $page->find('css', '#' . $id);
            if ($dateField && $dateField->isVisible()) {
                $this->fillField($id, $values['date']);
            }
            $this->selectOption($values['hour'], "hour_" . $id);
            $this->selectOption($values['min'], "min_" . $id);
            if ($this->getSession()->getPage()->findField("ampm_" . $id)) {
                $this->selectOption($values['ampm'], "ampm_" . $id);
            }
        }
    }

    /**
     * @When I fill in :formName form :fieldLabel with  :value
     */
    public function iFillInFormDate($formName, $fieldLabel, $value, $type = 'datetime')
    {
        if ($formName === 'customtime') {
            return $this->fillCustomTimeField(new DateTime($value), $fieldLabel);
        }
        $date = date("Y-m-d", strtotime($value));
        $page = $this->getSession()->getPage();
        $activeInterface = $this->getActiveInterface();
        $translatedFieldLabel = $this->translate($fieldLabel);
        $transDay = $this->translate('Day');
        $transMonth = $this->translate('Month');
        $transLowerMonth = $this->translate('month');
        $transYear = $this->translate('year');
        $transYearCaps = $this->translate('Year');
        $transDayLower = $this->translate('day');
        $field = $page->findField($translatedFieldLabel);
        if (!$field) {
            $field = $page->find('xpath', "//span[contains(.,'$translatedFieldLabel')]");
        }
        if (!$field || !$field->hasAttribute('id')) {
            $field = $page->find('xpath', "//span/parent::div[contains(.,'$translatedFieldLabel')]");
        }
        if (!$field || !$field->hasAttribute('id')) {
            $field = $page->find('xpath', "//label[contains(.,'$translatedFieldLabel')]");
        }
        if ((($fieldLabel === "Start Time" || $fieldLabel === "End Time") && $activeInterface === 'faculty') || ($formName === "from" || $formName === "to")) {
            $fromTo = ($formName === "to") ? 3 : 1;
            $field = $page->find('xpath', "//*[contains(text(),'$translatedFieldLabel')]/following::input[$fromTo]");
        }
        if (strpos($fieldLabel, '>')) {
            try {
                $sectionAndField = explode('>', $fieldLabel);
                $fieldMonthValue = "{$sectionAndField[1]}" . " month";
                $fieldYearValue = "{$sectionAndField[1]}" . " year";
                $monthField = $page->find('xpath', "//h2[text()='{$sectionAndField[0]}']/../..//select[@aria-label='$fieldMonthValue'] | //h3[text()='{$sectionAndField[0]}']/../..//select[@aria-label='$fieldMonthValue']");
                $yearField = $page->find('xpath', "//h2[text()='{$sectionAndField[0]}']/../..//select[@aria-label='$fieldYearValue'] | //h3[text()='{$sectionAndField[0]}']/../..//select[@aria-label='$fieldYearValue']");
                $monthField->selectOption($this->translate(date("F", strtotime($value))));
                $yearField->selectOption(date("Y", strtotime($value)));
                return;
            } catch (\Exception $e) {
                throw new \Exception("$fieldLabel Not Found");
            }
        }
        if ($field && $field->hasAttribute('id')) {
            $label_id = $field->getAttribute('id');
            $id = preg_replace('/_field.+/', '', $label_id);
            if (strpos($value, ":") !== false) {
                $hour = date("h", strtotime($value));
                $min = date("i", strtotime($value));
                $ampm = date("a", strtotime($value));
                $dateField = $page->find('css', '#' . $id);
                if ($dateField && $dateField->isVisible()) {
                    $this->fillField($id, $date);
                }
                $this->selectOption($hour, "hour_" . $id);
                $this->selectOption($min, "min_" . $id);
                if ($this->getSession()->getPage()->findField("ampm_" . $id)) {
                    $this->selectOption($ampm, "ampm_" . $id);
                }
            } elseif (strpos($value, "Aug") !== false || strpos($value, "May") !== false || strpos($value,
                "Dec") !== false) {
                // Graduation date ranges in CSM are represented by these specific months
                $this->selectOption($this->translate(date("F", strtotime($value))), "month_" . $id);
                $this->selectOption(date("Y", strtotime($value)), "year_" . $id);
            } elseif ($type === 'date') {
                if ($formName === 'ocr') {
                    if ($this->locale === 'pt_br' || $this->locale === 'es_419') {
                        $date = $this->translateDateMonth('ocr_month', $value);
                    } elseif ($this->locale === 'ar_sa') {
                        $date = $this->translateDate($value);
                    }
                    $this->selectOption($date, $id);
                } else {
                    $this->selectOption($this->translate(date("F", strtotime($value))), "month_" . $id);
                    $this->selectOption(date("d", strtotime($value)), "day_" . $id);
                    $this->selectOption(date("Y", strtotime($value)), "year_" . $id);
                }
            } elseif ($type === 'calendar') {
                $date = date("Y-F-j", strtotime($value));
                $this->iSelectDate($fieldLabel, $date);
            } else {
                $this->fillField($id, $date);
            }
        } else {
            $field = $page->find('xpath', "//span[text()='$translatedFieldLabel']//following::input[1][not(@id)]");
            if (strpos($value, "Aug") !== false || strpos($value, "May") !== false || strpos($value, "Dec") !== false) {
                // Graduation date ranges in CSM are represented by these specific months
                $month = $page->find('xpath',
                    "//*[text()='$translatedFieldLabel']//following::select[@ng-model='$transLowerMonth'][1]");
                $month->selectOption($this->translate(date("F", strtotime($value))));
                $year = $page->find('xpath',
                    "//*[text()='$translatedFieldLabel']//following::select[@ng-model='$transYear'][1]");
                $year->selectOption(date("Y", strtotime($value)));
            } elseif ($field) {
                $field->setValue($date);
                $field->blur();
                $this->iWait(3000);
            } elseif ($fieldLabel === 'Expiration Date' && $formName === 'e') {
                $month = $page->find('xpath',
                    "//*[contains(.,'$translatedFieldLabel')]/following::select[contains(@title,'$transLowerMonth')]");
                $month->selectOption($this->translate(date("F", strtotime($value))));
                $year = $page->find('xpath',
                    "//*[contains(.,'$translatedFieldLabel')]/following::select[contains(@title,'$transYear')]");
                $year->selectOption(date("Y", strtotime($value)));
            } else {
                $languageMonth = null;
                $ocr_check = $page->find('xpath', "//*[text()='" . $this->translate('OCR Session') . "']");
                if ($ocr_check && in_array(self::$tag, ['PT-CSM', 'ES-CSM'])) {
                    $translatedFieldLabel = ucwords($translatedFieldLabel);
                    $languageMonth = mb_strtolower($this->translate(date("F", strtotime($value))));
                }
                $field = $page->find('xpath', "//div[text()='$translatedFieldLabel']");
                if ($field) {
                    $day_check = $page->find('xpath',
                        "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title='$transDay' or @aria-label='$transDay' or @aria-label='$transDayLower']/child::option[@selected]");
                    $selected_date = $day_check ? $day_check->getText() : 0;
                    if ($selected_date > 28) {
                        $day = $page->find('xpath',
                            "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title='$transDay' or @aria-label='$transDay' or @aria-label='$transDayLower']");
                        $day->selectOption('28');
                    }
                    $year = $page->find('xpath',
                        "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title=' $transYear' or @aria-label=' $transYear']");
                    if (!$year) {
                        $year = $page->find('xpath', "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title='$transYearCaps' or @aria-label='$transYearCaps']");
                    }
                    $year->selectOption(date("Y", strtotime($value)));
                    $month = $page->find('xpath',
                        "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title='$transMonth' or @aria-label='$transMonth']");
                    if ($languageMonth) {
                        $month->selectOption($languageMonth);
                    } else {
                        $month->selectOption($this->translate(date("F", strtotime($value))));
                    }
                    $day = $page->find('xpath',
                        "//div[text()='$translatedFieldLabel']/following-sibling::div//select[@title='$transDay' or @aria-label='$transDay' or @aria-label='$transDayLower']");
                    $day->selectOption(date("d", strtotime($value)));
                } else {
                    throw new \Exception("DatePicker => $translatedFieldLabel not found");
                }
            }
        }
        if (in_array($formName, ['infosession', 'ocr', 'appt'])) {
            if ($formName === "appt") {
                ($fieldLabel === "Start Date") ? ($this->startDate = $value) : $this->endDate = $value;
            } else {
                $this->dateAsLabel = $this->translateDate($value);
                $this->actualDate = $value;
            }
        }
        $this->confirmPopup();
    }

    /**
     * @Given I fill :label from :start to :end
     * @Given I select :label from :start to :end
     */
    public function iFillFromTo($label, $start, $end)
    {
        $page = $this->getSession()->getPage();
        $label = $this->translate($label);
        $field = $page->findField($label);
        if (!$field) {
            $field = $page->find('xpath', "//label[contains(.,'" . $label . "')]");
        }
        if ($field && strpos($label, 'timespan') === false) {
            $label_id = $field->getAttribute('id');
            $id = preg_replace('/field.+/', '', $label_id);
            if (strpos($start, ":") !== false && strpos($end, ":") !== false) {
                $this->fillField("popupcal_" . $id . "_start_", date("Y-m-d", strtotime($start)));
                $this->selectOption(date("h", strtotime($start)), "hour_" . $id . "_start_");
                $this->selectOption(date("i", strtotime($start)), "min_" . $id . "_start_");
                $this->selectOption(date("s", strtotime($start)), "sec_" . $id . "_start_");
                $this->selectOption(date("a", strtotime($start)), "ampm_" . $id . "_start_");
                $this->fillField("popupcal_" . $id . "_end_", date("Y-m-d", strtotime($end)));
                $this->selectOption(date("h", strtotime($end)), "hour_" . $id . "_end_");
                $this->selectOption(date("i", strtotime($end)), "min_" . $id . "_end_");
                $this->selectOption(date("s", strtotime($end)), "sec_" . $id . "_end_");
                $this->selectOption(date("a", strtotime($end)), "ampm_" . $id . "_end_");
            } else {
                $this->fillField($id . "_start_", date("Y-m-d", strtotime($start)));
                $this->fillField($id . "_end_", date("Y-m-d", strtotime($end)));
            }
        } else {
            $timespan = $page->find('xpath', "//select[contains(@id,'_$label')]");
            if ($timespan) {
                $intStart = (int) $start;
                $intEnd = (int) $end;
                $id = $timespan->getAttribute('id');
                $spans = $page->findall('xpath', "//select[@id='$id']/option");
                foreach ($spans as $span) {
                    $value = (int) $span->getAttribute('value');
                    if ($value >= $intStart && $value <= $intEnd) {
                        if ($value === $intStart) {
                            $timespan->selectOption($start);
                        } elseif ($value === $intEnd) {
                            $this->additionallySelectOption($id, $end);
                            break;
                        } else {
                            $this->additionallySelectOption($id, $span->getAttribute('value'));
                        }
                    }
                }
            } else {
                $field = $page->find('xpath', "//span[normalize-space(text())='$label']");
                if ($field && $field->hasAttribute('id')) {
                    $id = $field->getAttribute('id');
                    $selector = "div[aria-labelledby='$id'] div";
                    $fromDate = $page->find('css', $selector . " input[aria-label='From'][type='date']" . "," . $selector . " input[aria-label='From'][type='text']");
                    $toDate = $page->find('css', $selector . " input[aria-label='To'][type='date']" . "," . $selector . " input[aria-label='To'][type='text']");
                    if ($page->find('xpath', "//span[normalize-space(text())='$label']/parent::div/parent::div//input[@type='text' and (@aria-label='From' or @aria-label='To')]")) {
                        $fromDate->setValue(date("Y-m-d", strtotime($start)));
                        $toDate->setValue(date("Y-m-d", strtotime($end)));
                    } else {
                        $fromDate->setValue(date("m-d-Y", strtotime($start)));
                        $toDate->setValue(date("m-d-Y", strtotime($end)));
                    }
                } else {
                    $field = $page->find('xpath', "//*[contains(@ng-model, '$label')]");
                    if ($field) {
                        $fromDate = $page->find('xpath', "//input[contains(@is-open, '$label') and (@ng-model='summaryDateRange.dateRange.from')]");
                        $toDate = $page->find('xpath', "//input[contains(@is-open, '$label') and (@ng-model='summaryDateRange.dateRange.to')]");
                        if ($page->find('xpath', "//input[contains(@is-open, '$label') and (@ng-model='summaryDateRange.dateRange.from' or @ng-model='summaryDateRange.dateRange.to')]")){
                               $fromDate->setValue(date("n-j-Y", strtotime($start)));
                               $toDate->setValue(date("n-j-Y", strtotime($end)));
                           }
                       } else {
                        throw new \Exception("Date Range field => $label not found");
                    }
                }
            }
        }
    }

    /**
     * @Then /^I will (not )?see option "([^"]*)" in "([^"]*)" field$/
     */
    public function iWillSeeOptionInField($not, $option, $field)
    {
        if (!in_array($option, $this->getOptions($field)) xor $not) {
            throw new \Exception('Option ' . $option . ($not ? '' : ' not') . ' exists in ' . $field);
        }
    }

    private function getOptions($field)
    {
        static $options = [];
        if (!isset($options[$field])) {
            $page = $this->getSession()->getPage();
            $xpath = "(//span[normalize-space(text())='$field'] | //label[normalize-space(text())='$field'])";
            $element = $page->find('xpath', $xpath . "/following::div[contains(@class,'widget search_filter_multiselect') or contains(@class,'field-widget')]");
            if ($element) {
                $element->focus();
                $element->click();
                $list = $page->findAll('xpath', $xpath . "/../..//ul/li");
                foreach ($list as $option) {
                    $options[$field][] = trim($option->getText());
                }
                $element->click();
            } else {
                throw new \Exception($field . ' not found');
            }
        }
        return $options[$field];
    }

    /**
     * @Given /^I should (not )?see "([^"]*)" field with options:$/
     */
    public function iShouldSeeFieldWithOptions($not, $field, TableNode $table)
    {
        foreach ($table->getHash() as $record) {
            if ($not) {
                $this->iWillSeeOptionInField($not, $record['option'], $field);
            } else {
                $this->iWillSeeOptionInField(null, $record['option'], $field);
            }
        }
    }

    /**
     * @Given I set daily timespans to :start to :end
     */
    public function iSetDailyTimespans($start, $end)
    {
        $page = $this->getSession()->getPage();
        $start_date = strtotime(date("Y-m-d", strtotime($this->startDate)));
        $end_date = strtotime(date("Y-m-d", strtotime($this->endDate)));
        $diff = ($end_date - $start_date) / 60 / 60 / 24;
        $saturday = $this->translate("Saturday");
        $sunday = $this->translate("Sunday");
        $weekEnd = "//*[contains(@id,'timespan') and (contains(text(),'$saturday') or contains(text(),'$sunday'))]";
        if ($diff >= 6) {
            $days = [
                "mon_timespan",
                "tue_timespan",
                "wed_timespan",
                "thu_timespan",
                "fri_timespan",
                "sat_timespan",
                "sun_timespan"
            ];
            $element = $page->find('xpath', $weekEnd);
            $day_limit = ($element) ? 7 : 5;
            for ($day_num = 0; $day_num < $day_limit; $day_num++) {
                $this->iFillFromTo($days[$day_num], $start, $end);
            }
        } elseif ($diff < 6) {
            $dayConverted = $this->startDate;
            for ($limit = 0; $limit <= $diff; $limit++) {
                $day = mb_strtolower(date('D', strtotime($dayConverted)));
                if (($day !== "sat" && $day !== "sun") || $page->find('xpath', $weekEnd)) {
                    $this->iFillFromTo($day . "_timespan", $start, $end);
                }
                $dayConverted = date('D', strtotime('+1 days', strtotime($dayConverted)));
            }
        }
    }

    public function selectRecord($record)
    {
        $page = $this->getSession()->getPage();
        $getActiveInterface = $this->getActiveInterface();
        if ($getActiveInterface === 'manager') {
            $this->iClickToButton("Select None");
        }
        $checkedAll = $page->find('xpath', "//*[@id='checkbox-empty' and contains(@style,'none')]");
        $checked = $page->find('xpath', "//tr[contains(@id,'row_') and contains(@class,'active')][1]");
        if (!$checkedAll && isset($record['optional']) && $record['optional'] === 'all') {
            $selectAllXpath = "//*[@id='checkbox-empty' or @title='" . $this->translate("Select/Clear All") . "']";
            $this->focusAndClick($selectAllXpath);
        } elseif (!$checked && !$checkedAll) {
            $first_record = $page->find('xpath', "//tr[contains(@id,'row_')][1]//input[@type='checkbox'] | //td[@class='select-column table-column-padding lst_td']//input[@type='checkbox' and @id='checkbox_0']");
            if ($first_record && !$checked) {
                $first_record->check();
            }
        } else {
            if ($checked && $record['optional'] === 'all') {
                $page->find('xpath', "//*[@id='checkbox-partial' and contains(@style,'display: inline-block;')]")->click();
                $this->iWillSee("0 items selected");
                $page->find('xpath', "//*[@id='checkbox-empty' or @title='" . $this->translate("Select/Clear All") . "']")->click();
            }
        }
        if ($getActiveInterface === 'manager') {
            $this->iWillSee("items selected");
            $this->iWillNotSee("(0 items selected)");
        } else {
            $this->iWillSee("selected");
        }
    }

    /**
     * @Given I use :records batch tool
     */
    public function iUseBatchTool($records)
    {
        $recordValue = explode(">", $records);
        $batchField = ['option', 'value', 'type', 'optional'];
        $this->records = [];
        foreach ($batchField as $index => $opt) {
            $this->records[0][$opt] = trim($recordValue[$index]);
        }
        $this->iTestBatchOptions();
    }

    /**
     * @Then I test batch options
     */
    public function iTestBatchOptions()
    {
        $page = $this->getSession()->getPage();
        $activeInterface = $this->getActiveInterface();
        foreach ($this->records as $record) {
            $this->selectRecord($record);
            $managerbatch = $page->find('xpath', "//*[contains(text(),'" . $this->translate("Batch Options") . "')]");
            if ($managerbatch) {
                try {
                    $this->iClickToButton("Batch Options");
                } catch (\Exception $e) {
                    $retry = 0;
                    if ($activeInterface === 'manager') {
                        $element = $page->find('xpath',
                            "//*[contains(.,'" . $this->translate("Batch Options") . "')]/parent::table[contains(@class,'hp_selection')]");
                        if (!$element) {
                            $element = $page->find('xpath', "//button[contains(text(),'Batch Options')]");
                        }
                    } else {
                        $element = $page->find('xpath',
                            "//*[contains(.,'" . $this->translate("Batch Options") . "')]/parent::button");
                    }
                    $btnActive = $element->getAttribute('class');
                    while ((strpos($btnActive, "disabled") !== false) && ($retry < 5)) {
                        $this->iWait(3000);
                        $btnActive = $element->getAttribute('class');
                        ++$retry;
                    }
                    $this->clickXpath("//*[contains(text(),'" . $this->translate("Batch Options") . "')]");
                }
            }
            $untranslatedOption = $record['option'];
            $option = $this->translate($untranslatedOption);
            $value = $record['value'];
            if (($record['type'] !== 'set' || $record['type'] !== 'clear') && $value !== '[new message]') {
                $value = $this->translate($value);
            } elseif ($value === '[new message]' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                $msg = $this->translate('new message');
                $value = "[" . $msg . "]";
            }
            $type = $record['type'];
            switch ($type) {
                case 'set':
                    $element = $page->find('xpath', "//*[@hp_val='" . $option . "']");
                    if ($element) {
                        try {
                            $this->iScrollToMiddle();
                            $element->focus();
                            $element->click();
                        } catch (\Exception $e) {
                            $this->iScrollToMiddle();
                            $element->focus();
                            $element->click();
                        }
                        if ($value !== '') {
                            $cleanValue = preg_replace('/\.*\//', '', $value);
                            $valueDiv = $this->findXpathElement(".//*[contains(@id,'" . $option . "')]//div[@title='" . $cleanValue . "']");
                            if ($valueDiv) {
                                $valueDiv->focus();
                                $valueDiv->click();
                                $backButton = $page->find('xpath', "//a[text()='back']");
                                if ($backButton) {
                                    $backButton->click();
                                    $this->iTryReloadAndBatchOption();
                                    $this->focusAndClick("//*[@hp_val='" . $option . "']");
                                    $this->focusAndClick(".//*[contains(@id,'" . $option . "')]//div[@title='" . $cleanValue . "']");
                                }
                            } elseif ($page->find('xpath', "//input[@value='Fewer Filters']")) {
                                $page->find('xpath', "//*[@hp_val='" . $this->translate('Edit') . "']")->mouseOver();
                                $this->iClickToButton('Fewer Filters');
                                $this->iTestBatchOptions();
                            }
                        }
                    } else {
                        $optionText = $page->find('xpath', "//a[@class='dropdown-item hasSubMenu' and normalize-space(text())='$option']");
                        if(!$optionText) {
                            $this->iTryReloadAndBatchOption();
                        }
                        $this->clickLink($option);
                        if (strpos($value, '>') !== false) {
                            $this->setBatchOption($value);
                            $this->confirmNumberInModal($option, $value);
                        } else {
                            $this->iWillSee($value);
                            $this->clickLink($value);
                        }
                    }
                    if ($untranslatedOption === 'Set Flag' || $untranslatedOption === 'Assign Contact Type') {
                        if (ucfirst($record['optional']) === 'Edit') {
                            $this->clickXpath("//tr[contains(@id,'row_')][1]//a[@title='" . $this->translate(ucfirst($record['optional'])) . "']");
                        } else {
                            $this->clickXpath("//tr[contains(@id,'row_')][1]//a[@class='ListPrimaryLink' or @title='Review' or @href][1]");
                        }
                        if ($untranslatedOption === 'Set Flag') {
                            $this->scheduleDetailsCollapse();
                            $checkedOption = $page->find('xpath', "//*[@title='$value' or @aria-label='$value']");
                        } else {
                            $transValue = $this->translate($value);
                            $contactType = $this->translate('Contact Type');
                            $checkedOption = $page->find('xpath', "//*[@title='$contactType: " . $transValue . "' or @data-text-value='$contactType: " . $transValue . "']");
                        }
                        if (!$checkedOption->isChecked()) {
                            throw new \Exception("$value not set");
                        }
                        $this->iScrollBackToTop();
                        $this->iClickToButton("cancel");
                    }
                    break;
                case 'clear':
                    $page->find('xpath', "//*[@hp_val='" . $option . "']")->click();
                    if ($value !== '') {
                        $this->iWillSee($value);
                        $page->find('xpath', ".//*[contains(@id,'" . $option . "')]//div[@title='" . $value . "']")->click();
                    }
                    $this->clickXpath("//tr[contains(@id,'row_')][1]//a[@class='ListPrimaryLink' or @title='Review' or @href][1]");
                    $checkedOption = null;
                    if ($untranslatedOption === 'Remove Contact Type') {
                        $transValue = $this->translate($value);
                        $contactType = $this->translate('Contact Type');
                        $checkedOption = $page->find('xpath', "//*[@title='$contactType: " . $transValue . "' or @data-text-value='$contactType: " . $transValue . "']");
                    } elseif ($untranslatedOption === 'Clear Flag') {
                        $this->scheduleDetailsCollapse();
                        $checkedOption = $page->find('xpath', "//*[@title='$value' or @aria-label='$value']");
                    }
                    if (!$checkedOption || $checkedOption->isChecked()) {
                        throw new \Exception("$value not cleared");
                    }
                    $this->iScrollBackToTop();
                    $this->iClickToButton("cancel");
                    break;
                case 'print':
                    $titleElem = $this->findXpathElement("//tr[contains(@id,'row_')][1]/td[2]");
                    $title = $titleElem->getText();
                    $page->find('xpath', "//*[@hp_val='" . $option . "' or @aria-label='$option']")->click();
                    $this->iSwitchToBrowserTab(1);
                    $this->iWillSee($title);
                    $this->browserTab = true;
                    break;
                case 'delete':
                    $tag = ($record['option'] === 'Remove Job Blast Agent(s)' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) ? 'title' : 'hp_val';
                    $page->find('xpath', "//*[@$tag='" . $option . "']")->click();
                    if ($value) {
                        $page->find('xpath', "//*[@$tag='" . $value . "']")->click();
                    }
                    $this->confirmNumberOfRecords();
                    break;
                case 'add note':
                    $page->find('xpath', "//*[@hp_val='" . $option . "']")->click();
                    $this->iWait(3000);
                    $this->getSession()->getDriver()->switchToIFrame("batch_add_note_inner");
                    [$category, $subject, $notes] = explode(',', $value);
                    $this->selectOption($category, 'Category');
                    $subjectField = $this->translate('Subject', 'form contact_notes_default');
                    $this->fillField($subjectField, $subject);
                    $this->fillField('Notes', $notes);
                    try {
                        $this->clickXpath("//input[contains(@value,'Add Note for ')]");
                    } catch (\Exception $e) {
                        $this->confirmPopup();
                    }
                    $this->confirmNumberOfRecords();
                    $this->confirmPopup();
                    $this->iShouldSeeNoErrors();
                    break;
                case 'flat_batch':
                    $flatBatch = $page->find('xpath', "//a[contains(text(),'" . $option . "')]");
                    if ($flatBatch) {
                        try {
                            $this->iWillSee($option);
                            $flatBatch->focus();
                            $flatBatch->click();
                        } catch (\Exception $e) {
                            $this->getSession()->executeScript('window.scrollTo(100,300);');
                            $flatBatch->focus();
                            $flatBatch->click();
                        }
                        if ($value !== '') {
                            $cleanValue = preg_replace('/\.*\//', '', $value);
                            $valueDiv = $this->findXpathElement("//a[contains(text(),'" . $option . "')]/following::*[contains(@id,'submenu_container')]//a[contains(text(),'" . $cleanValue . "')]");
                            if ($valueDiv) {
                                $valueDiv->focus();
                                $valueDiv->click();
                            }
                        }
                    }
                    break;
                case 'edit':
                    $page->find('xpath', "//*[@hp_val='" . $option . "']")->click();
                    $this->iWait(3000);
                    $this->getSession()->getDriver()->switchToIFrame("batch_edit_inner");
                    $editFieldValues = explode(",", $value);
                    $this->translate($editFieldValues[0]);
                    $this->translate($editFieldValues[1]);
                    $this->iCheckRadioButton2($editFieldValues[0], $editFieldValues[1]);
                    $translateValue = $this->translate('Edit');
                    try {
                        $this->clickXpath("//input[contains(@value,'$translateValue')]");
                    } catch (\Exception $e) {
                        $this->confirmPopup();
                    }
                    $this->confirmNumberOfRecords();
                    $this->confirmPopup();
                    $this->iShouldSeeNoErrors();
                    break;
                case 'merge':
                    $page->find('xpath', "//*[@hp_val='" . $option . "']")->click();
                    $continueSession = $page->find('xpath', "//a[text()='[continue session]']");
                    if ($continueSession) {
                        $continueSession->click();
                        $this->selectRecord($record);
                        $this->iClickToButton("Batch Options");
                        $page->find('xpath', "//*[@hp_val='" . $option . "']")->click();
                    }
                    $this->clickXpath("//input [contains(@alt,'" . $value . "')]");
                    $translateValue = $this->translate("merge");
                    $this->clickXpath("//input[@value='$translateValue']");
                    break;
                default:
                    if ($record['option'] === 'Set as Primary Contact' && self::$tag === 'AR-CSM') {
                        $option = 'تعيين كـ ';
                    }
                    $option = $page->find('xpath', "//*[@hp_val='" . $option . "' or @title='" . $option . "']");
                    if ($option) {
                        $option->click();
                    } else {
                        throw new \Exception("Could not find hier-pick $option for $type");
                    }
            }
        }
    }

    private function confirmNumberOfRecords()
    {
        $page = $this->getSession()->getPage();
        $countXpath = "//div/p/strong";
        $countElement = $page->find('xpath', $countXpath);
        if ($countElement) {
            $count = preg_replace('/\D/', '', $countElement->getText());
            $this->fillField('num_of_purge_records', $count);
            $confirm_btn = $this->translate('Confirm');
            $this->useButton($confirm_btn);
        }
    }

    private function confirmNumberInModal($option, $value)
    {
        $page = $this->getSession()->getPage();
        $this->iWillsee('Mark Activity');
        $instructionXpath = "//input[@ng-reflect-name='instructions']/following-sibling::p | //input[@type='hidden']/following-sibling::p[1]";
        $instructionElement = $page->find('xpath', $instructionXpath);
        if (!$instructionElement) {
            throw new \Exception("Confirm pop-up not found");
        }
        preg_match('/the ([\d]+) selected (students|contacts|employers)/', $instructionElement->getText(), $count);
        if ($count[1] === "0") {
            $this->iTryReloadAndBatchOption();
            $this->clickLink($option);
            $this->setBatchOption($value);
            $this->iWillsee('Mark Activity');
            if ($instructionElement) {
                preg_match('/the ([\d]+) selected (students|contacts|employers)/', $instructionElement->getText(), $count);
                if ($count[1] === "0") {
                    throw new \Exception("data loading issue");
                }
            }
        }
        $page->find('xpath', "//input[@ng-reflect-name='students_number'] | //input[@type='number']")->setValue($count[1]);
        $this->useButton($this->translate('Submit'));
    }

    private function iTryReloadAndBatchOption()
    {
        $this->reload();
        $this->iWillsee('Batch Options');
        $this->iClickToButton("Select None");
        $this->iClickToButton("Select All");
        $this->clickXpath("//*[contains(text(),'" . $this->translate("Batch Options") . "')]");
    }

    private function setBatchOption($value)
    {
        $subValues = explode(">", $value);
        $first = true;
        foreach ($subValues as $subValue) {
            if ($first) {
                $this->iWillSee($subValue);
            } else {
                $this->iShouldAlsoSee($subValue);
            }
            $this->clickLink($subValue);
        }
    }

    /**
     * @Given I open :tile homepage tile
     */
    public function iOpenTile($tile)
    {
        $card = $this->translate($tile);
        $xpath = "//h2[contains(@class,'tile-title') and text()='$tile'] | //div[@class='tile-title' and text()='$tile']";
        $element = $this->findXpathElement($xpath);
        $element->focus();
        try {
            $element->click();
        } catch (\Exception $e) {
            $this->iScrollToMiddle();
            $element->click();
        }
    }

    /**
     * @Given I open :card card
     * @Given I open :card tile
     */
    public function iOpenCard($card)
    {
        $card = $this->translate($card);
        $xpath = "//h3[contains(@class,'card-title') and text()='$card'] | //div[@class='tile-title' and text()='$card'] | //h2[contains(@class, 'tile-title') and text()='$card']";
        $element = $this->findXpathElement($xpath);
        $element->focus();
        try {
            $element->click();
        } catch (\Exception $e) {
            $this->iScrollToMiddle();
            $element->click();
        }
    }

    /**
     * @Then /^I should (not )?see richtext toolbar icons:$/
     */
    public function iShouldSeeRichtextToolbarIcons($not = null, TableNode $table = null)
    {
        foreach ($table->getHash() as $tool) {
            if (!$not) {
                try {
                    $this->assertElementOnPage($tool['type'] . '.ql-' . $tool['class']);
                } catch (\Exception $e) {
                    $this->assertElementOnPage($tool['type'] . '.mce-i-' . $tool['class']);
                }
            } else {
                $xpath = "//" . $tool['type'] . "[@class='ql-" . $tool['class'] . "']/ancestor::form-richtext/div[not(@hidden)]";
                if ($this->getSession()->getPage()->find('xpath', $xpath)) {
                    throw new \Exception('Element ' . $tool['type'] . ' Exists');
                }
            }
        }
    }

    public function assertElementContains($label, $value)
    {
        if (preg_match('/\((.*?)\)/', $label, $type)) {
            $label = trim(str_replace($type[0], '', $label));
            $page = $this->getSession()->getPage();
            $field = $this->getField($type[1], $label);
            $element = $page->find($field['selector type'], $field['selector']);
            if (!$element) {
                throw new \Exception('Element ' . $label . ' not found with ' . $field['selector']);
            }
            $textInElement = $element->getText();
            if (!($textInElement === $value || strpos($element->getText(), $value))) {
                throw new \Exception('Expected Text => ' . $value . ' not found in ' . $label);
            }
        } else {
            parent::assertElementContains($label, $value);
        }
    }

    private function getField($type, $label)
    {
        $fieldsPath = [
            'rich text' => [
                'selector type' => 'css',
                'selector' => 'div.ql-editor>p'
            ],
            'sortable container' => [
                'selector type' => 'xpath',
                'selector' => '//div[normalize-space(text())="' . $label . '"]/../../parent::div'
            ],
            'multiselect' => [
                'selector type' => 'xpath',
                'selector' => '//span[normalize-space(text())="' . $label . '"]/../following-sibling::div | //label[normalize-space(text())="' . $label . '"]/../following-sibling::div'
            ]
        ];
        if (!isset($fieldsPath[$type])) {
            throw new \Exception($type . ' not found in selectors list');
        }
        return $fieldsPath[$type];
    }

    private function scheduleDetailsCollapse()
    {
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', "//*[@class='sidebar-title'][contains(., 'Schedule Details')]");
        if ($element) {
            $element->focus();
            $element->click();
        }
    }

    /**
     * @Then /^"([^"]*)" should be required$/
     */
    public function fieldRequired($field)
    {
        $this->iWillSee("$field is required.");
    }

    /**
     * @Then I should see :new image
     * @Then I should see :new record
     */
    public function iShouldSeeRecord($new)
    {
        if (strpos($new, "infosession") !== false) {
            if ($this->locale === 'en') {
                $date1 = date(" d, Y", strtotime($this->dateAsLabel));
            } else {
                $date1 = $this->dateAsLabel;
            }
            $this->iWillSee($date1);
        } elseif (strpos($new, ' in ') !== false) {
            [$text, $field] = explode(' in ', $new);
            if ($text === 'custom') {
                $text = $this->customSkill;
            } elseif ($field !== "skills") {
                $text = $this->translate($text);
            }
            $page = $this->getSession()->getPage();
            try {
                $iframe = $this->findXpathElement("//div[contains(@id,'$field')]//table//div");
                $list = $page->findAll('xpath', "//div[contains(@id,'_frame')]/ul/li//*[contains(text(),'$text') or contains(text(),'" . mb_strtolower($text) . "')]");
                if (!$list) {
                    throw new \Exception("$text not found in $field");
                }
            } catch (\Exception $e) {
                $iframe = $page->find('xpath', "//div[contains(@id,'$field')]//iframe");
                if ($iframe) {
                    $iframe_id = $iframe->getAttribute('id');
                    $this->getSession()->getDriver()->switchToIFrame($iframe_id);
                } else {
                    throw new \Exception("$field not found in page");
                }
                $selection = $this->translate('Selection');
                $list = $page->findAll('xpath', "//li[@title='$selection: $text']/span[2]");
                if (!$list) {
                    throw new \Exception("$text not found in $field");
                }
                $this->getSession()->getDriver()->switchToIFrame(null);
            }
        } elseif (strpos($new, "now") !== false) {
            $currentdate = date("M d, Y", strtotime($new));
            $this->iWillSee($currentdate);
        } else {
            $image = $this->getSession()->getPage()->find('xpath',
                "//img[contains(@alt,'" . $new . "') or contains(@title,'" . $this->translate($new) . "')] | //div/*[@role='img' and @aria-label='$new']");
            if (!$image || !$image->isVisible()) {
                throw new \Exception("Expected image '$new' was not found");
            }
        }
    }

    /**
     * @When /^I should see (\d+) (\w+) views$/
     */
    public function iShouldSeeViews($count, $views)
    {
        $row = ($views === "Student") ? 2 : 1;
        $element = ".views-sidebar tr:nth-of-type($row) td:nth-of-type(2)";
        $this->assertElementContainsText($element, $count);
    }

    /**
     * @Then I should not see :new record
     */
    public function iShouldNotSeeRecord($new)
    {
        if (strpos($new, "infosession") !== false) {
            if ($this->locale === 'en') {
                $date1 = date(" d, Y", strtotime($this->dateAsLabel));
            } else {
                $date1 = $this->dateAsLabel;
            }
            $this->assertPageNotContainsText($date1);
        }
    }

    /**
     * @When I unselect :new
     */
    public function iUnselect($new)
    {
        $text = preg_replace('/ in .+/', '', $new);
        $field = preg_replace("/$text in /", '', $new);
        if ($text === 'custom') {
            $text = $this->customSkill;
        } elseif ($field !== "skills") {
            $text = $this->translate($text);
        }
        if ($field === 'major') {
            $major_read_only = $this->getSession()->getPage()->find('xpath',
                "//*[contains(@id,'scrollable_readonly_ms_field_')]");
            if ($major_read_only) {
                $major_read_only->focus();
            }
        }
        $this->getSession()->wait(3000);
        try {
            $this->clickXpath("//span[text()='$text']/preceding-sibling::button");
        } catch (\Exception $e) {
            $iframe = $this->getSession()->getPage()->find('xpath', "//div[contains(@id,'$field')]//iframe");
            if ($iframe) {
                $iframe_id = $iframe->getAttribute('id');
                $this->getSession()->getDriver()->switchToIFrame($iframe_id);
            } else {
                throw new \Exception("$field not found in page");
            }
            $selection = $this->translate('Selection');
            $this->clickXpath("//li[@title='$selection: $text']/span[1]/img");
            $this->getSession()->getDriver()->switchToIFrame(null);
        }
    }

    /**
     * @Then I get text from textarea and logged in as :arg1
     * @Then I get text from textarea and go to :arg1
     */
    public function iGetTextFromTextareaAndLoginAs($interface)
    {
        $xpath = ".//*[@name='job_board_html' or @class='inline ng-binding']";
        $element = $this->findXpathElement($xpath)->getText();
        $this->iAmLoggedOut();
        if ($interface !== 'publicurl' && $interface !== 'w3school') {
            $this->iAmLoggedInAs($interface);
            $this->visit($this->locatePath($element));
            $this->getSession()->getDriver()->maximizeWindow();
            $this->getSession()->wait(3000);
        } elseif ($interface === 'w3school') {
            $this->visit($this->locatePath('https://www.w3schools.com/html/tryit.asp?filename=tryhtml_basic'));
            $this->getSession()->getDriver()->maximizeWindow();
            $this->iWillSee("The content of the body element");
            $this->getSession()->getPage()->find('xpath', "//span[@class='cm-m-xml']")->click();
            $this->switchToIFrame('iframeResult');
            $this->postValueToXpath("//body[@contenteditable='false']", $element);
        } else {
            $this->visit($this->locatePath($element));
            $this->getSession()->getDriver()->maximizeWindow();
        }
    }

    /**
     * @When I attach :filename to :field
     * @When I attach :filename to :field with :position as Image Focal Point
     */
    public function attachFile($filename, $field, $position = null)
    {
        $currentUrl = $this->getSession()->getCurrentUrl();
        $element = $this->getSession()->getPage()->find('xpath', "//input[contains(@id,'attachment')]");
        if ($element) {
            $element->focus();
            $element->click();
        }
        if ($field === 'Booth Map' || $field === 'Drag and drop files here' || $field === 'Upload File') {
            $path = $this->getMinkParameter('files_path') . $filename;
            $addMap = $this->getSession()->getPage()->find('xpath', "//input[@type='file']");
            $addMap->attachFile($path);
        } elseif ($field === "Choose Image Selector") {
            parent::attachFileToField('Choose Image', $filename);
            $this->iWillSee("Crop");
            $this->iClickToButton("save");
            $this->assertElementOnPage("[id='image_small_thumbnail']");
        } elseif ($field === 'Banner Image') {
            $this->attachFileWithFocalPoint($field, $filename, $position);
        } else {
            $this->iWait(3000);
            if ($field === 'Preview') {
                $select = $this->translate('Select:');
                $element = $this->getSession()->getPage()->find('xpath', "//label[text()='$select']");
                if (!$element) {
                    $clear = $this->translate('clear');
                    $this->clickXpath("//label[text()='Preview']/following::input[@value='$clear']");
                    $this->iWillSee('Select:');
                }
                $this->attachFileToField('Select:', $filename);
            } else {
                $this->attachFileToField($field, $filename);
            }
        }
    }

    public function attachFileToField($field, $filename)
    {
        $field = $this->translate($field);
        parent::attachFileToField($field, $filename);
    }

    //Attach image in MoxieManager
    private function attachFileWithFocalPoint($field, $filename, $position)
    {
        $this->iWillSee($field);
        $this->pressbutton('Choose Image');
        $this->iWillSee('Image Manager');
        $this->iClickToButton('Thumbnails');
        $page = $this->getSession()->getPage();
        $selector = "div[aria-label='Image Manager'] div.moxman-container-body div[title='$filename']";
        $this->spin(static function () use ($page, $selector, $filename) {
            $image = $page->find('css', $selector);
            if ($image) {
                $image->click();
                return true;
            }
            throw new \Exception($filename . ' not found');
        });
        if ($position) {
            $this->selectOption($position, 'Image Focal Point');
        }
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" value$/
     */
    public function iShouldSeeValue($not, $value)
    {
        $translatedValue = $this->translate($value);
        if (strpos($value, "(copy)") !== false) {
            $textToTranslate = '(copy)';
            $translatedText = $this->translate($textToTranslate);
            $translatedValue = str_replace($textToTranslate, $translatedText, $value);
        }
        if ((strpos($value, "Learn about {") !== false) && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $translatedLearnAboutText = [
                'AR-CSM' => 'تعرف على',
                'ES-CSM' => 'Obtener información sobre',
                'PT-CSM' => 'Aprenda sobre'
            ];
            $translatedLearnAboutText = $translatedLearnAboutText[self::$tag];
            $translatedValue = str_replace("Learn about", "$translatedLearnAboutText", $value);
            $translatedValue = str_replace([ '{', '}' ], '', $translatedValue);
        }
        $this->spin(function () use ($translatedValue, $not) {
            if (!$this->getSession()->getPage()->find('xpath', "//*[@value = '$translatedValue' or @placeholder = '$translatedValue' or @aria-label = '$translatedValue' or @title = '$translatedValue' or text()= '$translatedValue'] | //button[contains(@class,'input-submit btn') and text()='$translatedValue']") xor $not) {
                throw new \Exception(($not ? 'Found' : 'Could not find') . " $translatedValue value.");
            }
            return true;
        });
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" in Bold$/
     */
    public function iShouldSeeInBold($not, $value)
    {
        $this->spin(function () use ($value, $not) {
            if (!$this->getSession()->getPage()->find('xpath', "//b[text()='$value']") xor $not) {
                throw new \Exception($value . ($not ? 'is Bolded' : 'is not Bolded'));
            }
            return true;
        });
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" module$/
     */
    public function iWillNotSeeModule($not, $module)
    {
        $navIcons = [
            'Employers' => 'icn-building',
            'Exp Learning' => 'icn-backpack',
            'Counseling Appointment' => 'icn-counseling',
            'Resume Books' => 'icn-contact_book'
        ];
        $navIcon = ".nav-item i[class='" . $navIcons[$module] . "']";
        if ($not) {
            $this->assertElementNotOnPage($navIcon);
        } else {
            $this->assertElementOnPage($navIcon);
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" text (\d+) times? in page$/
     */
    public function iWillSeeTextTimeInPage($not, $text, $times)
    {
        if (!$not) {
            $this->iWillSee($text);
        }
        $page = $this->getSession()->getPage()->getText();
        $occurence = substr_count($page, $text);
        if (($occurence === (int) $times) xor $not) {
            return true;
        }
        throw new \Exception($text . ' appears ' . $occurence . ' times');
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" link$/
     */
    public function iWillSeeLink($not, $link)
    {
        if ((!($this->getSession()->getPage()->findLink($link))) xor $not) {
            throw new \Exception($link . ' link is ' . ($not ? 'found' : 'not found'));
        }
    }

    public function iWillSee($text)
    {
        if (strpos($text, 'of 1 results') !== false && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $text = (self::$tag === 'PT-CSM' || self::$tag === 'ES-CSM') ? '1 - 1 de 1 resultados' : '1 - 1 من 1 النتائج';
        } elseif (strpos($text, 'This action is final') !== false && in_array(self::$tag,
            ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            if (self::$tag === 'ES-CSM') {
                $text = "Nota: Esta acción es definitiva y no se puede deshacer.";
            } else {
                $text = (self::$tag === 'PT-CSM') ? 'Anotação: Esta ação é definitiva e não pode ser desfeita.' : 'لاحظة: هذا الإجراء نهائي ويتعذر التراجع عنه.';
            }
        } elseif (strpos($text, ' is required') !== false && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $string = preg_split('/ [is ]/', $text);
            if ($string[0] === "Time Slot" && self::$tag === 'PT-CSM') {
                $text1 = $this->translate($string[0], 'form rsvp_manager');
            } else {
                $text1 = $this->translate($string[0]);
            }
            $text2 = $this->translate('is required');
            $text = $text1 . " " . $text2;
        } elseif (($text === 'San Diego' || $text === 'Spokane') && self::$tag === 'AR-CSM') {
            $text = ($text === 'San Diego') ? 'سان دييغو' : 'سبوكين';
        } elseif (strpos($text, 'Your updates have') !== false && self::$tag === 'AR-CSM') {
            $trim_text = $this->translate($text);
            $text = str_replace('.', '', $trim_text);
        } elseif (strpos($text, 'GPA does not fall') !== false && in_array(self::$tag,
            ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $text1 = $this->translate('Your');
            $text2 = $this->translate('GPA');
            $text3 = $this->translate('does not fall within the desired range for this position.');
            $text = $text1 . " " . $text2 . " " . $text3;
        } elseif ($text === '1 seat left' && in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
            $translatedText = [
                'AR-CSM' => 'يتبقى 1 مقعد',
                'ES-CSM' => '1 lugar disponible',
                'PT-CSM' => '1 vaga restante(s)'
            ];
            $text = $translatedText[self::$tag];
        } elseif (strpos($text, 'Your account has been disabled.') !== false) {
            $text = str_replace('  ', ' ', $this->translate($text));
            $page = $this->getSession()->getPage();
            $errorText = $page->find('xpath', "//*[normalize-space(text())='$text']");
            if ($errorText) {
                return true;
            }
        }
        parent::iWillSee($text);
    }

    protected function assertPageNotContainsRegExp(string $regexp, int $attemp = 0)
    {
        $pageText = $this->getSession()->getPage()->getText();
        $matches = [];
        if (preg_match_all($regexp, $pageText, $matches)) {
            if ($attemp < 5) {
                // sometimes it takes a second for translations to render, lets try again
                sleep(1);
                return $this->assertPageNotContainsRegExp($regexp, ++$attemp);
            }
            throw new \Exception("Page should not contain $regexp but found " . implode(', ', $matches[0]));
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" field is required$/
     */
    public function iWillSeeFieldIsRequired($not, $field)
    {
        $page = $this->getSession()->getPage();
        $requiredField = $page->find('xpath', "//*[text()='$field']/following-sibling::span/span[text()='*']");
        if ($requiredField xor $not) {
            return true;
        }
        throw new \Exception('Field ' . $field . ' is found as ' . ($not ? 'required' : 'not required'));
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" tag for "([^"]*)"$/
     */
    public function iWillSeeTagFor($not, $tagName, $job)
    {
        $page = $this->getSession()->getPage();
        if (preg_match('/\b(now|day|days|week|weeks|month|months|year|years|Next|next)\b/', $job)) {
            $job = date('M d, Y', strtotime($job));
        }
        $tagFound = $page->find('xpath', "//a[contains(text(),'$job')]/ancestor::li/descendant::span[text()='" . ucfirst($tagName) . "'] | //span[contains(text(),'$job')]/../following-sibling::div[contains(@class,'ng-star-inserted')]//span[text()='$tagName']");
        if ($tagFound xor $not) {
            return true;
        }
        throw new \Exception($tagName . ' tag is ' . ($not ? 'found for ' : 'not found for ') . $job);
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" position under "([^"]*)" section$/
     */
    public function iWillSeePositionUnderSection($not, $position, $status)
    {
        $page = $this->getSession()->getPage();
        $positionFound = $page->find('xpath', "//h2[text()='$status']/parent::div/div/descendant::*[starts-with(text(),'$position')]");
        if ($positionFound xor $not) {
            return true;
        }
        throw new \Exception($positionFound . ' is ' . ($not ? ' found ' : ' not found ') . ' under ' . $status);
    }

    /**
     * @When /^I search for job "([^"]*)"$/
     */
    public function iSearchForJob($keywords)
    {
        $page = $this->getSession()->getPage();
        $activeInterface = $this->getActiveInterface();
        $translatedText = $this->translate('Advanced Search');
        $searchbuttonxpath = "//a[contains(.,'$translatedText')]/preceding::input[1]";
        $element = $page->find('xpath', $searchbuttonxpath);
        if ($element === null) {
            $jobSection = $page->find('xpath', "//*[text()='" . $this->translate('Job Postings') . "']");
            if ($activeInterface === 'students' && $jobSection) {
                $this->clickXpath("//div[@class='flickity-viewport']/following::*[@aria-label='Select box activate' or @aria-label='Keyword search' or @id='keyword_input']");
            }
            $this->fillField($this->translate('Keywords'), $keywords);
            $translateSearch = $this->translate("Search");
            $this->clickXpath("//*[(@class='btn btn_alt-default' or contains(@class, 'input-submit') or @class='btn btn_default btn-sticky') and (@value='$translateSearch' or text()='$translateSearch')]");
        } else {
            $this->fillField('jobfilters_keywords_', $keywords);
            $this->iWait(3000);
            $search_btn = $page->find('xpath', $searchbuttonxpath);
            if ($search_btn) {
                $search_btn->focus();
                if ($search_btn->isVisible()) {
                    $search_btn->click();
                    $this->iWait(10000);
                }
            } else {
                throw new \Exception("Search button was not found");
            }
        }
    }

    /**
     * @When I search job :job and see :text
     */
    public function iSearchJobAndSee($job, $text)
    {
        $activeInterface = $this->getActiveInterface();
        $page = $this->getSession()->getPage();
        try {
            $this->iSearchForJob($job);
            $this->iWillSee($text);
        } catch (\Exception $e) {
            $searchButton = $page->find('xpath', "//div[contains(@class, 'job-discovery-filters')]/div/div[contains(@class, 'search-buttons')]/div/button[text()='" . $this->translate("Search") . "']");
            if ($activeInterface === 'students' && $searchButton) {
                $searchButton->click();
                $this->iWillSee($text);
            } else {
                throw new \Exception("Search button was not found");
            }
        }
    }

    /**
     * @When I search for :keywords record
     */
    public function iSearchForRecord($keywords)
    {
        $searchboxpath = "//input[contains(@id,'keywords_')]";
        $translateSearch = $this->translate('Search');
        $searchbuttonxpath = "//input[(contains(@value,'earch') or contains(@value,'esquisa') or contains(@value,'بحث') or contains(@value,'uscar') or contains(@value,'úsqueda')) and @type='submit'] | //button[text()='$translateSearch' or text()='ﺐﺤﺛ' and @type='submit']";
        $searchbox = $this->findXpathElement($searchboxpath);
        $id = $searchbox->getAttribute('id');
        $this->fillField($id, $keywords);
        $this->clickXpath($searchbuttonxpath);
    }

    /**
     * @When I search translated :keyword record
     */
    public function iSearchTranslated($keyword)
    {
        $this->iSearchForRecord($this->translate($keyword));
    }

    /**
     * @When I impersonate :interface :name
     * @When I impersonate :interface :name with :email email
     */
    public function impersonateAs($interface, $name, $email = '')
    {
        if ($interface === "employer") {
            $this->iNavigateTo("Employers>Contacts");
        } elseif ($interface === 'faculty') {
            $this->iNavigateTo('More>Faculty');
        } elseif ($interface === 'student') {
            $this->iNavigateTo("Students>Students");
        }
        try {
            $this->iSearchFor($email ?: $name);
        } catch (\Exception $e) {
            $this->clickXpath("//div[@class='titlebar']/div[@class='back']");
            $this->iSearchFor($email ?: $name);
        }
        $this->iClickLink($name);
        $this->iClickLink("Login As");
        $this->switchToIFrame("Login as frame");
            try {
                $this->iClickToButton('Open in a separate window');
            } catch (\Exception $e) {
                $this->iWillSee("You have opened a separate window");
                $this->iClickLink('Close other window');
                $this->iClickToButton('Open in a separate window');
            }
    }

    private function iGetInstanceUrl()
    {
        $matches = [];
        preg_match('/(sanity.*)-csm\./', $this->getMinkParameter('base_url'), $matches);
        $match = $matches[1];
        $match_lookup = [
            'sanity' => 'Sanity Full',
            'sanity-law' => 'Sanity Law',
            'sanity-modular' => 'Sanity Modular',
            'sanity-ae' => 'Sanity AE',
            'sanity-mse' => 'Sanity MSE',
            'sanity-cf' => 'Sanity CF',
            'sanity-enterprise' => 'Sanity Enterprise',
            'sanity-interprise' => 'Sanity Interprise',
            'sanity-stage' => 'Sanity Full',
            'sanity-ae-stage' => 'Sanity AE',
            'sanity-mse-stage' => 'Sanity MSE',
            'sanity-modular-stage' => 'Sanity Modular',
            'sanity-law-stage' => 'Sanity Law',
            'sanity-cf-stage' => 'Sanity CF',
            'sanity-enterprise-stage' => 'Sanity Enterprise',
            'sanity-interprise-stage' => 'Sanity Interprise'
        ];
        return $match_lookup[$match];
    }

    public function setLocale($locale)
    {
        $instanceLocale = [
            'sanity-interprise' => 'pt_br',
            'sanity-ar-interprise' => 'ar_sa',
            'sanity-es-interprise' => 'es_419',
            'sanity-interprise-stage' => 'pt_br',
            'sanity-ar-interprise-stage' => 'ar_sa',
            'sanity-es-interprise-stage' => 'es_419',
        ];

        $matches = [];
        if (preg_match('/(sanity.*)-csm\./', $this->getMinkParameter('base_url'), $matches)) {
            if (isset($instanceLocale[$matches[1]])) {
                $locale = $instanceLocale[$matches[1]];
            }
        }
        $this->locale = $locale;
    }

    /**
     * @Then I verify Instance Selection for OneStop
     */
    public function iVerifyInstanceSelection()
    {
        $page = $this->getSession()->getPage();
        $url = $this->iGetInstanceUrl();
        $searchbox = "school-picker-keyword-search";
        $this->fillField($searchbox, $url);
        $this->iWait(3000);
        $element = $page->find('xpath', "//div[contains(.,'$url')]/following-sibling::div/button/span");
        if ($element) {
            if (strpos($element->getAttribute('class'), 'muted') === false) {
                throw new \Exception("Local csm => $url selected");
            }
        } else {
            throw new \Exception("Page does not have schools list");
        }
    }

    /**
     * @Then I click public link :text
     */
    public function iClickPublicLink($text)
    {
        $text = $this->translate($text);
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', "//td[text()='" . $text . "']/following::td[1] | //td[@id='" . $text . "']//a[1]");
        if ($element) {
            $element->focus();
            $element->click();
            $this->iWait(3000);
        } else {
            throw new \Exception ('Public Link is not found');
        }
    }

    /**
     * @Given I click public link for :text
     */
    public function iClickPublicLinkFor($text)
    {
        $text = $this->translate($text);
        $page = $this->getSession()->getPage();
        $element = $page->find('xpath', "//div[contains(text(),'$text')]/following::a[1] | //a[contains(text(),'$text')]/following::a[1]");
        if (!$element) {
            throw new \Exception('Public Link not found for ' . $text);
        }
        $element->focus();
        $element->click();
    }

    /**
     * @Given I am logged in as :user to :instance
     */
    public function iAmLoggedInAsTo($user, $instance)
    {
        $interface = $this->getUserInterface($user);
        $url = $this->getMinkParameter('base_url');
        switch ($instance) {
            case 'FULL-CSM':
                if (strpos($url, '.test8') !== false) {
                    $url = 'https://sanity-csm.test8.symplicity.com';
                } else {
                    $url = 'https://sanity-stage-csm.symplicity.com';
                }
                break;
            case 'MSE-CSM':
                if (strpos($url, '.test8') !== false) {
                    $url = 'https://sanity-mse-csm.test8.symplicity.com';
                } else {
                    $url = 'https://sanity-mse-stage-csm.symplicity.com';
                }
                break;
            case 'LAW-CSM':
                if (strpos($url, '.test8') !== false) {
                    $url = 'https://sanity-law-csm.test8.symplicity.com';
                } else {
                    $url = 'https://sanity-law-stage-csm.symplicity.com';
                }
                break;
        }
        $this->visit($url . '/logout.php');
        $this->visit($url . "/$interface/");
        $this->iSubmitCorrectCredentials($user);
    }

    /**
     * @When /^I log out and back in as (\w+)$/
     */
    public function iAmLogOutAndLogInBack($interface)
    {
        $this->iAmLoggedOut();
        $page = $this->getSession()->getPage();
        $signInBtn = $this->translate('Sign In');
        if (!$page->find('xpath', "//*[@value='$signInBtn']")) {
            $this->iWillSee("What type of user are you?");
        } else {
            $this->iWillSee("You have been logged out.");
        }
        $this->iAmLoggedInAs($interface);
    }

    /**
     * @When I fill :value for :label
     */
    public function iFillValue($value, $label)
    {
        $label = $this->translate($label);
        $xpath = "//*[contains(@ng-click, '$label')]";
        $element = $this->getSession()->getPage()->find('xpath', $xpath);
        if ($element) {
            $element->click();
        }
        $xpath = "//input[@placeholder='$label' or @title='$label' or contains(@ng-blur, '$label')]";
        $element = $this->getSession()->getPage()->find('xpath', $xpath);
        if ($element) {
            $id = $element->getAttribute('id');
            $this->fillField($id, $value);
        } else {
            $element = $this->getSession()->getPage()->find('xpath', "//input[contains(@name, '$label')]");
            if ($element) {
                $name = $element->getAttribute('name');
                $this->fillField($name, $value);
            } else {
                throw new \Exception("Field => $label not found");
            }
        }
    }

    /**
     * @Then /^"([^"]*)" should be disabled$/
     */
    public function ifDisabled($button)
    {
        $button = $this->translate($button);
        $element = $this->getSession()->getPage()->find('xpath',
            "//*[@disabled and (@value='$button' or @name='$button' or @title='$button' or text()='$button' or @alt='$button' or @data-text-value='$button')] | //div[@class='header-text']/following::*[@disabled='true' and (//span[text()='$button'])]");
        if (!$element) {
            throw new \Exception("$button is enabled");
        }
    }

    /**
     * @Then /^"([^"]*)" should be enabled$/
     */
    public function ifEnabled($button)
    {
        $button = $this->translate($button);
        $element = $this->getSession()->getPage()->find('xpath',
            "//*[@disabled and (@value='$button' or @name='$button' or @title='$button' or text()='$button' or @alt='$button')] | //div[@class='header-text']/following::*[@disabled='true' and (//span[text()='$button'])]");
        if ($element) {
            throw new \Exception("$button is disabled");
        }
    }

    private function getOfCheckbox($checkbox)
    {
        $j = 1;
        $page = $this->getSession()->getPage();
        if (strpos($checkbox, ' of ') !== false) {
            [$label, $value] = explode(' of ', $checkbox);
            $label = $this->translate($label);
            $xpath = "//tr/td[contains(.,'$value')]//input[contains(@name,'$label')]";
            $element = $page->find('xpath', $xpath);
            if (!$element) {
                $headers = $page->findall('xpath',
                    "//table[@id='cspVar_coop_auto_offer_table' or @class='ocr_screening']//tr[1]/td");
                foreach ($headers as $header) {
                    if ($header->getText() === $label) {
                        break;
                    }
                    ++$j;
                }
                if (strpos($value, 'offer') !== false) {
                    $currentUrl = $this->getSession()->getCurrentUrl();
                    if ((strpos($currentUrl, 'law-csm.test') !== false)
                        && $value === "offer[12]"
                        && self::$tag === 'LAW-CSM'
                    ) {
                        $value = "offer[16]";
                    }
                    $xpath = "//td[$j]/input[contains(@name,'$value')]/following::input[contains(@name,'$value')]";
                } else {
                    $xpath = "//td[$j]/input[@value='$value']";
                }
                $element = $page->find('xpath', $xpath);
            }
            return $element;
        }
        return null;
    }

    public function assertCheckboxChecked($checkbox)
    {
        $element = $this->getOfCheckbox($checkbox);
        if ($element) {
            if (!$element->isChecked()) {
                throw new \Exception("Field -> $checkbox is not checked");
            }
        } else {
            $checkbox = $this->translate($checkbox);
            parent::assertCheckboxChecked($checkbox);
        }
    }

    public function assertCheckboxNotChecked($checkbox)
    {
        $element = $this->getOfCheckbox($checkbox);
        if ($element) {
            if ($element->isChecked()) {
                throw new \Exception("Field -> $checkbox is checked");
            }
        } else {
            $checkbox = $this->translate($checkbox);
            parent::assertCheckboxNotChecked($checkbox);
        }
    }

    private function fillResumeSections($field, $optional)
    {
        $page = $this->getSession()->getPage();
        $label = $page->find('xpath', "//label[normalize-space(text())='$field']");
        if ($label) {
            $id = $label->getAttribute('for');
            $params = explode('__', $id);
            $params[2] = $optional;
            return implode('__', $params);
        }
    }

    /**
     * @Then the :field should contain :value
     */
    public function fieldShouldContain($field, $value)
    {
        if (strpos($value, 'value : ') !== false) {
            [$label, $text] = explode(' : ', $value);
            $id = $this->underscore($field);
            $xpath = "//div[starts-with(@id,'dnf_class_values') and contains(@id,'_$id')]";
        } elseif (strpos($value, 'datetime : ') !== false || strpos($value, 'date : ') !== false) {
            $id = $this->underscore($field);
            [$label, $date] = explode(' : ', $value);
            $xpath = "//div[starts-with(@id,'dnf_class_values') and contains(@id,'_$id')]";
            if (strpos($value, 'datetime') !== false) {
                if (in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                    $text = date("F j, Y, g:i A", strtotime($date));
                    $text = $this->translateDateFormat($field, $text);
                } else {
                    $text = date("M d, Y, g:i A", strtotime($date));
                }
            } else {
                $text = date("F j, Y", strtotime($date));
                if (in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM'])) {
                    $text = $this->translateDateFormat($field, $text);
                }
            }
        } elseif (strpos($value, ' - ') !== false) {
            [$scheduleDay, $days] = explode(' - ', $value);
            $date = date("Y-m-d", strtotime($scheduleDay));
            $text = date("Y-m-d", strtotime("$date - $days"));
            $xpath = "//div[text() = '$field']/following-sibling::div//span/input";
        } else {
            $text = date("Y-m-d", strtotime($value));
            $xpath = "//div[text() = '$field']/following-sibling::div//span/input";
        }
        $element = $this->getSession()->getPage()->find('xpath', $xpath);
        $elementValue = $element->getAttribute('value');
        if (!$elementValue) {
            $elementText = $element->getText();
            if (strpos($elementText, $text) === false) {
                throw new \Exception("$field text does not contain $text");
            }
        } elseif ($elementValue !== $text) {
            throw new \Exception("$field value does not contain $text");
        }
    }

    public function isPresentInTable($col, $row, $value, $table): bool
    {
        $i = 1;
        $j = 1;
        $isPresent = false;
        if ($table === '') {
            $columnXpath = "//table[not(@role='presentation')]/tbody/tr[@class='SideBarHeader']/td | //table[not(@role='presentation')]/thead/tr/th";
            $rowXpath = "//table[not(@role='presentation')]/tbody/tr/td[1]";
        } else {
            $columnXpath = "//table[not(@role='presentation')]/thead/tr/th[normalize-space()='$table']/ancestor::table/thead/tr[2]/th";
            $rowXpath = "//table[not(@role='presentation')]/thead/tr/th[normalize-space()='$table']/ancestor::table/tbody/tr/td[1]";
        }
        $page = $this->getSession()->getPage();
        $tableHeaderReport = $page->find('xpath', "//table[not(@role='presentation')]/thead/tr/th[@class='sorting_disabled']/span[text()='Table Header']");
        if ($tableHeaderReport) {
            $columnXpath = "//table[not(@role='presentation')]/thead/tr/th/following::div[@class='col-name']/span";
            $rowXpath = "//table[not(@role='presentation')]/tbody/tr/td[2]";
        }
        $rows = $page->findAll('xpath', $rowXpath);
        $columns = $page->findAll('xpath', $columnXpath);
        foreach ($columns as $colcell) {
            if ($colcell->getText() === $col) {
                $getextCol = $colcell->getText();
                break;
            }
            if ($colcell->hasAttribute('colspan')) {
                $j += (int) $colcell->getAttribute('colspan');
            } else {
                ++$j;
            }
        }
        foreach ($rows as $rowcell) {
            $rowText = $rowcell->getText();
            if ($rowText === $row || $rowText === date("m-d", strtotime($row)) || strpos($rowText, $row) !== false) {
                if ($table === '') {
                    if ($tableHeaderReport) {
                        ++$j;
                    }
                $cellXpath = "//table[not(@role='presentation')]/tbody/tr[$i]/td[$j]";
                } else {
                    $cellXpath = "//table[not(@role='presentation')]/thead/tr/th[normalize-space()='$table']/ancestor::table/tbody/tr[$i]/td[" . ($j + 1) . "]";
                }
            $cell = $page->find('xpath', $cellXpath);
            $cellText = $cell ? $cell->getText() : '';
            $fullDate = date("M d Y", strtotime($value));
            $dayMonth = date("M d", strtotime($value));
                if ($cellText === $fullDate || strpos($cellText, $dayMonth) !== false || $cellText === $value) {
                    $isPresent = true;
                    break;
                }
            }
            ++$i;
        }
        return $isPresent;
    }

    /**
     * @Then I should see :col of :row as :value
     * @Then I should see :col of :row as :value in :table
     */
    public function iShouldSeeInTable($col, $row, $value, $table = '')
    {
        if (!$this->isPresentInTable($col, $row, $value, $table)) {
            throw new \Exception("Value => $value not found for $col of $row ($table)");
        }
    }

    /**
     * @Then I should not see :col of :row as :value
     * @Then I should not see :col of :row as :value in :table
     */
    public function iShouldNotSeeInTable($col, $row, $value, $table = '')
    {
        if ($this->isPresentInTable($col, $row, $value, $table)) {
            throw new \Exception("Value => $value found for $col of $row ($table)");
        }
    }

    public function assertElementOnPage($element)
    {
        $css = $this->findElementId($element);
        if ($css !== null) {
            $element = "#$css";
        }
        parent::assertElementOnPage($element);
    }

    public function assertElementNotOnPage($element)
    {
        $css = $this->findElementId($element);
        if ($css !== null) {
            $element = "#$css";
        }
        parent::assertElementNotOnPage($element);
    }

    /**
     * @AfterScenario
     */
    public function logScenarioResults($event)
    {
        static $client, $release, $allIds = [];
        $time = microtime(true) - $this->scenarioTimer;
        $suite = $event->getSuite();
        $suiteTags = $suite->getSetting('filters')['tags'];

        $scenario = $event->getScenario();
        if (method_exists($scenario, 'getOutlineTitle')) {
            $title = $scenario->getOutlineTitle();
        } else {
            $title = $scenario->getTitle();
        }
        $title = preg_replace('/\s+/', ' ', $title);
        $regRows = [];
        $testIds = [];
        if (preg_match_all('/\((\s*\w+-\d+[^)]*)\)/', $title, $regRows)) {
            foreach ($regRows[1] as $key => $ids) {
                $title = trim(str_replace($regRows[0][$key], '', $title));
                $testIds = array_unique(array_merge(
                    $testIds,
                    preg_split('#[,\s/]+#', preg_replace('/ (and|or) /i', ',', trim($ids)))
                ));
            }
        }
        sort($testIds);

        $feature = $event->getFeature();
        $tags = array_flip(array_unique(array_merge($scenario->getTags(), $feature->getTags())));
        unset($tags['javascript']);

        // FULL-CSM,nav,'Scenario title',DOCL-2|DOCL-3,FULL-CSM|CRITICAL,1,2.6
        $status = $event->getTestResult()->isPassed() ? 1 : 0;
        $featureName = preg_replace('#.+/features/(.+)\.feature#', '$1', $feature->getFile());
        if (!$release) {
            $release = 'dev';
            if (defined('INFRA_TYPE') && in_array(INFRA_TYPE, ['TEST', 'AWS_US'])) {
                if (strpos(INSTANCE_NAME, 'hf-') === 0) {
                    // hf-sanity-csm and hf-sanity-law-csm
                    $release = 'prod';
                } elseif (STAGE_INSTANCE) {
                    $release = 'stage';
                } elseif (TEST_HOSTED) {
                    $release = 'test';
                }
            }
        }
        if ($release === 'dev') {
            return;
        }
        if (!$client) {
            $client = \Elasticsearch\ClientBuilder::create()
            ->setHosts(["172.30.13.99:9200", "172.30.12.97:9200", "172.30.12.139:9200"])
            ->setRetries(3)
            ->build();
        }
        $id = md5(INSTANCE_NAME . $suiteTags . $featureName . $title);
        if (isset($allIds[$id])) {
            \Base::staticRaiseError("Duplicate behat log id for $suiteTags - $featureName - $title");
        } else {
            $allIds[$id] = true;
        }
        $utc = new \DateTimeZone('UTC');
        $dt = new \DateTime('now', $utc);
        $modString = $dt->format('Y-m-d\TH:i:s');
        $results = [
            'instance' => INSTANCE_NAME,
            'release' => $release,
            'tag' => $suiteTags,
            'feature' => $featureName,
            'scenario' => $title,
            'tests' => $testIds,
            'pass' => $status === 1,
            'duration' => (int) $time,
            'completed' => $modString,
        ];
        try {
            $client->index([
                'index' => 'behat_scenarios',
                'id' => $id,
                'body' => $results
            ]);
        } catch (\Exception $e) {
            $testIds = implode(', ', $testIds);
            \Base::staticRaiseError("Could not save $testIds for $suiteTags (" . ($status ? 'PASS' : 'FAIL') . ") " . $e->getMessage());
        }
    }

    /**
     * @AfterScenario
     */
    public function resetPicklistValue()
    {
        if ($this->review === 1) {
            $pizzaid = "cost_4";
            $pizzacost = "85.00";
            $pizzacostid = "hp_val[cost_4]";
            $searchbox = $this->translate('Keywords');
            $searchvalue = "catering";
            $interface = "manager";
            $this->iAmLoggedInAs($interface);
            $this->iNavigateTo("More>Tools>Picklists");
            $this->fillField($searchbox, $searchvalue);
            $this->iClickToButton("apply search");
            $this->iWillSee("Description");
            $this->iClickLink("Events: Catering Options");
            $this->iClickToButton($pizzaid);
            $this->fillField($pizzacostid, $pizzacost);
            $this->iClickToButton("save");
            $this->iWillSee($pizzacost);
            $this->iClickToButton("submit");
            $this->review = null;
        }
    }

    /**
     * @AfterScenario
     */
    public function deletePicklist()
    {
        if ($this->review === 2) {
            $interface = "manager";
            $searchbox = $this->translate('Keywords');
            $this->iAmLoggedInAs($interface);
            $this->iNavigateTo("More>Tools>Picklists");
            $this->fillField($searchbox, $this->picklist);
            $this->iClickToButton("apply search");
            $this->iWillSee("Description");
            $value = $this->picklist;
            if ($value) {
                $this->iClickLink($value);
                $this->clickXpath("//tr/td[contains(.,'" . $this->newPick . "')]//following-sibling::td/input");
            }
            $this->iClickToButton("save");
            $this->iOpenTab("Archived Picks");
            $archivedPickCheckbox = "//td[text()='$this->newPick']/preceding::input[1]";
            $this->clickXpath($archivedPickCheckbox);
            $this->iClickToButton("delete");
            $this->review = null;
        }
    }

    /**
     * @AfterScenario
     */
    public function resetSystemSettings()
    {
        $this->inAfterScenario = true;
        if ($this->changedSettings) {
            $this->iAmLoggedInAs('manager');
            $this->iNavigateTo("More>Tools>System Settings");
            foreach ($this->settingsNav as $nav) {
                $this->iNavigateToSettings($nav[0], $nav[1]);
                foreach ($this->modifiedSettings as $setting) {
                    $setting[0] = $this->translate($setting[0]);
                    $xpath = "//*[text()=\"$setting[0]\"]/following::input[@alt=' " . $setting[1] . "' or @alt=\"$setting[0] $setting[1]\"][1]";
                    $element = $this->getSession()->getPage()->find('xpath', $xpath);
                    if ($element && !$element->hasAttribute('checked')) {
                        $element->focus();
                        $element->click();
                    }
                }
                $this->iClickToButton("Save Selections");
            }
            $this->navigatedToSettings = false;
            $this->changedSettings = false;
        }
    }

    /**
     * @Then In system setting I should see :row as :value in :table
     */
    public function iShouldSeeInTableInSystemSetting($row, $value, $table)
    {
        if (!$this->isPresentInTableSystemSetting($row, $value, $table)) {
            throw new \Exception("Value => $value not found for $row of $table");
        }
    }

    private function isPresentInTableSystemSetting($row, $value, $table): bool
    {
        $isPresent = false;
        $cellXpath = "//table[contains(@id, '$table')]/tbody/tr/td[text()[normalize-space()='$row']]/following-sibling::td/input";
        $rowValue = $this->findXpathElement($cellXpath);
        if ($rowValue->getValue() === $value) {
            $isPresent = true;
        }
        return $isPresent;
    }

    /**
     * @Then /^I will (not )?see more than one record in the list$/
     */
    public function iSeeMoreThanOneListRecord($not = null)
    {
        $xpath = "//div[@class='list_results']//span[1]";
        $xpathElement = $this->findXpathElement($xpath)->getText();
        $xpathElement = explode(' ', $xpathElement);
        $xpathElement = array_pop($xpathElement);
        if (($xpathElement < 2) xor $not) {
            if (!$xpathElement) {
                $xpathElement = '0';
            }
            throw new \Exception("Failed because record count is $xpathElement");
        }
    }

    /**
     * @When I open :form form
     */
    public function iOpenForm($form)
    {
        if ($form === 'job') {
            return $this->iOpenJobForm();
        }
        $this->clickXpath("//span[text()='$form']");
    }

    /**
     * @Then I select :value from :field field
     */
    public function iSelectValueFromField($value, $field)
    {
        $this->iWillSeeLoadingIndicator();
        $field = $this->translate($field);
        $translatedvalue = mb_strtolower($this->translate($value));
        if ($field) {
            $this->clickXpath("//*[@aria-label='$field' or contains(@aria-label,'$field')] | //select[normalize-space(@ng-reflect-name)='$field']");
            if ($value) {
                $this->clickXpath("//*[text()]/following::md-content[last()]/child::md-option/div[text()='$value' or text()='$translatedvalue'] | //select[normalize-space(@ng-reflect-name)='$field']/option[normalize-space(text())='$value' or normalize-space(text())='$translatedvalue']");
            }
        }
    }

    /**
     * @Then I will be on :value
     */
     public function iWillSeeUrl($value)
     {
         $this->spin(function () use ($value) {
             $currentUrl = $this->getSession()->getCurrentUrl();
             if ($value !== $currentUrl) {
                 throw new \Exception("URL Mismatch : $currentUrl");
             }
             return true;
         });
     }

     /**
     * @Then /^I will (not )?see "([^"]*)" button$/
     */
    public function iWillSeeButton($not, $button)
    {
        $buttonValue = $this->translate($button);
        $page = $this->getSession()->getPage();
        $this->spin(function () use ($not, $buttonValue, $page) {
            $buttonExist = $page->find('xpath', "//button[contains(text(),'$buttonValue')] | //input[@value='$buttonValue'] | //button[not(@disabled)]/span[text()='$buttonValue']");
            if (!$buttonExist xor $not) {
                throw new \Exception("Button $buttonValue is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @Then /^I will (not )?see option "([^"]*)" under "([^"]*)" navigation$/
     */
    public function iWillSeeOptionInNavigation($not, $option, $navigation)
    {
        $page = $this->getSession()->getPage();
        $optionValue = $this->translate($option);
        $navigationValue = $this->translate($navigation);
        $this->clickXpath("//nav[@id='nav-container']//span[contains(text(), '$navigationValue')] | //div[@id='user-avatar']//button[contains(@aria-label,'$navigationValue')]");
        $this->spin(function () use ($not, $optionValue, $navigationValue, $page) {
            $linkExist = $page->find('xpath', "//nav[@id='nav-container']//span[contains(text(), '$navigationValue')]/../..//span[contains(., '$optionValue')] | //button[contains(@aria-label, '$navigationValue')]/following-sibling::div[@id='user-tools']//*[contains(text(),'$optionValue')]");
            if (!$linkExist xor $not) {
                throw new \Exception("Link $optionValue is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @When /^I (\w+) "([^"]*)" from keyword$/
     */
    public function iWillCheckKeywordOptions($option, $value)
    {
        $page = $this->getSession()->getPage();
        $translatedValue = $this->translate($value);
        $xpath_element = $page->find('xpath', "//label[text()='$translatedValue']/preceding-sibling::input[1]");
        if ($option === 'check' && $xpath_element) {
            $xpath_element->check();
        } elseif ($option === 'uncheck' && $xpath_element) {
            $xpath_element->uncheck();
        }
        if (!$xpath_element) {
            throw new \Exception("Value not found: $translatedValue");
        }
    }

    /**
     * @When I scroll to middle
     */
    public function iScrollToMiddle()
    {
        $this->getSession()->executeScript('window.scrollTo(200,500);');
    }

    /**
     * @Given I select :label from :firstStart to :firstEnd and :secondStart to :secondEnd
     */
    public function iFillFromAnd($label, $firstStart, $firstEnd, $secondStart, $secondEnd)
    {
        $page = $this->getSession()->getPage();
        $label = $this->translate($label);
        $timespan = $page->find('xpath', "//select[contains(@id,'_$label')]");
        if ($timespan) {
            $intStart = (int) $firstStart;
            $intEnd = (int) $firstEnd;
            $intSecondStart = (int) $secondStart;
            $intSecondEnd = (int) $secondEnd;
            $id = $timespan->getAttribute('id');
            $spans = $page->findall('xpath', "//select[@id='$id']/option");
            foreach ($spans as $span) {
                $value = (int) $span->getAttribute('value');
                if ((($value >= $intStart) && ($value <= $intEnd)) || (($value >= $intSecondStart) && ($value <= $intSecondEnd))) {
                    if ($value === $intStart) {
                        $timespan->selectOption($firstStart);
                    } elseif ($value === $intEnd) {
                        $this->additionallySelectOption($id, $firstEnd);
                    } elseif ($value === $intSecondStart) {
                        $this->additionallySelectOption($id, $secondStart);
                    } elseif ($value === $intSecondEnd) {
                        $this->additionallySelectOption($id, $secondEnd);
                        break;
                    } else {
                        $this->additionallySelectOption($id, $span->getAttribute('value'));
                    }
                }
            }
        }
    }

    /**
     * @Then I will see :message on popup
     */
    public function iWillSeeOnPopup($message)
    {
        $this->spin(function () use ($message) {
            $message = $this->translate($message);
            $element = $this->getSession()->getPage()->find('xpath', "//span[text()='$message']");
            if (!$element) {
                throw new \Exception("$message not found");
            }
            return true;
        });
    }

    /**
     * @Then I click :field and select :value
     */
    public function iClickAndSelect($field, $value)
    {
        $field = $this->translate($field);
        $element = $this->getSession()->getPage()->find('xpath', "//div[@id='$field']//select[1]");
        if ($element) {
            $this->clickXpath("//div[@id='$field']//select[1]");
            $element->selectOption($value);
        } else {
            throw new \Exception("$value not found");
        }
    }

    /**
     * @When /^I (\w+) "([^"]*)" option for "([^"]*)" filter$/
     */
    public function iSelectSidebarFilter($option, $value, $field)
    {
        $page = $this->getSession()->getPage();
        $translatedField = $this->translate($field);
        $translatedValue = $this->translate($value);
        $this->iScrollToMiddle();
        $field_element = $page->find('xpath', "//label[text()='$translatedField']");
        $field_element->focus();
        $field_element->click();
        $this->iWillSee($field);
        $value_element = $page->find('xpath', "//label[text()='$translatedField']/following::div[@style='display: block;']//input[@title='$translatedValue']");
        if ($option === 'check' && $value_element) {
            $value_element->check();
        } elseif ($option === 'uncheck' && $value_element) {
            $value_element->uncheck();
        }
    }

    /**
     * @Then page will load and I will see :value
     */
    public function pageLoadAndIWillSee($value)
    {
        $this->iWillSeeLoadingIndicator();
        $this->iWillSee($value);
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" tab$/
     */
    public function iWillSeeTab($not, $tab)
    {
        $page = $this->getSession()->getPage();
        $tabValue = $this->translate($tab);
        $this->spin(function () use ($not, $tabValue, $page) {
            $tabExist = $page->find('xpath', "//table[@class='tabs']//td//a[@title='$tabValue'] | //div[@id='tabs_']//a[@title='$tabValue'] | //div[contains(@id,'tabs_') or @role='tablist']//a[(@role='tab' and (@title=\"$tabValue\" or (@translate and text()=\"$tabValue\") or normalize-space(text())=\"$tabValue\"))]");
            if (!$tabExist xor $not) {
                throw new \Exception("Tab $tabValue is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" card$/
     */
    public function iWillSeeCard($not, $card)
    {
        $page = $this->getSession()->getPage();
        $cardValue = $this->translate($card);
        $this->spin(function () use ($not, $cardValue, $page) {
            $tabExist = $page->find('xpath', "//div[@class='tile-title' and text()='$cardValue']");
            if (!$tabExist xor $not) {
                throw new \Exception("Card $cardValue is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @When I navigate to Counseling section
     */
    public function iNavigateToCounselingSection()
    {
        $translatedUserMenu = $this->translate('User Menu');
        $translatedCounselingAppointment = $this->translate('My Counseling Appointments');
        $translatedCounseling = $this->translate('Counseling');
        $this->spin(function () use ($translatedUserMenu, $translatedCounselingAppointment, $translatedCounseling) {
            if (self::$tag === "LAW-CSM") {
                $this->iNavigateTo("$translatedUserMenu>$translatedCounselingAppointment");
            } else {
                $this->iNavigateTo("$translatedCounseling");
            }
            return true;
        });
    }

    /**
     * @Given I select :time from :counselor counselor
     */
    public function iSelectCounselorAvailability($time, $counselor)
    {
        $page = $this->getSession()->getPage();
        $minuteInterval = 15;
        $time = new DateTime($time);
        $timeZoneChange = $time;
        $timeZoneChange->setTimeZone(new \DateTimeZone('America/New_York'));
        $values['hour'] = $timeZoneChange->format('g');
        $values['min'] = floor($timeZoneChange->format('i') / $minuteInterval) * $minuteInterval;
        $values['ampm'] = $timeZoneChange->format('a');
        $hour = intval($values['hour']);
        $minutes = intval($values['min']);
        if ($minutes === 0) {
            $minutes = '00';
        } elseif ($minutes === 60) {
            $minutes = '00';
            $hour = $timeZoneChange->modify('+1 hour')->format('g');
        }
        $amPm = $values['ampm'];
        $timeToClick = $hour . ":" . $minutes . " " . $amPm;
        $this->iWillNotSee('Searching');
        $moreAvailableTime = $page->find('xpath', "//*[contains(text(), 'More available times')]");
        if ($moreAvailableTime) {
            $moreAvailableTime->focus();
            $moreAvailableTime->click();
        }
        $this->iScrollBackToTop();
        $this->clickXpath("//*[normalize-space(text())='$timeToClick']//following::*[text()='$counselor'][1]");
    }

    /**
     * @Given /^I will see "([^"]*)" (not )?completed/
     */
    public function iWillSeeEvaluationCompleted($evaluationName, $not = null)
    {
        $page = $this->getSession()->getPage();
        $translatedEvaluationName = $this->translate($evaluationName);
        if ($not) {
            $evaluationStatus = $page->find('xpath', "//td[text()='$translatedEvaluationName']/../td/span[text()='Not Done']");
        } else {
            $evaluationStatus = $page->find('xpath', "//td[text()='$translatedEvaluationName']/../td/span[text()='Done']");
        }
        if (!$evaluationStatus) {
            throw new \Exception("$evaluationName is" . ($not ? ' ' : ' not ') . "Completed");
        }
    }

    /**
    * @Given I select :value from Global Search
    */
    public function iSelectGlobalSearchDropDown($value)
    {
        $searchInXpath = "//span[text()='Search In...']";
        $this->focusAndClick($searchInXpath);
        $dropDownValue = "//div[@class='chosen-drop']/ul/li[text()='$value']";
        $this->focusAndClick($dropDownValue);
    }

    /**
    * @Given I search :value from Global Search
    */
    public function iSearchValueInGlobalSearch($value)
    {
        $page = $this->getSession()->getPage();
        $searchXpath = "//input[@placeholder='Type something to search']";
        $this->postValueToXpath($searchXpath, $value);
        $page->findById("qs-text")->keyPress(13);
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" link for "([^"]*)" record/
     */
    public function iWillSeeEvaluationLink($not, $evaluationName, $recordName)
    {
        $page = $this->getSession()->getPage();
        $translatedEvaluationName = $this->translate($evaluationName);
        $translatedRecordName = $this->translate($recordName);
        $this->spin(function () use ($page, $not, $translatedEvaluationName, $translatedRecordName) {
            $tabExist = $page->find('xpath', "//div[contains(string(), '$translatedRecordName')]/following-sibling::div//span[contains(text(),'$translatedEvaluationName')]");
            if (!$tabExist xor $not) {
                throw new \Exception("Evaluation link $translatedEvaluationName is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" in dialog/
     */
    public function iShouldSeeInDialogbox($not, $label)
    {
        $page = $this->getSession()->getPage();
        $translatedLabel = $this->translate($label);
        $this->spin(function () use ($not, $translatedLabel, $page) {
            $dialog = $page->find('xpath', "//md-dialog-content[@class='md-dialog-content']//h2[text()='$translatedLabel'] | //div[contains(@class, 'ui-dialog-titlebar')]/span[text()='$translatedLabel'] | //div[contains(@class, 'modal-dialog')]/div/confirmation-modal/div[@class='modal-header']/h3[text()='$translatedLabel']");
            if (!$dialog xor $not) {
                throw new \Exception("$translatedLabel is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @Then I click :dialog button in dialog
     */
    public function iClickButtonInDialogbox($button)
    {
        $page = $this->getSession()->getPage();
        $translatedButton = $this->translate($button);
        $this->spin(function () use ($translatedButton, $page) {
            $xpath = "//md-dialog[@md-theme='default']//button[text()='$translatedButton'] | //div[contains(@class, 'ui-dialog-buttonpane')]/div/button[text()='$translatedButton'] | //div[contains(@class, 'modal-dialog')]/div/confirmation-modal/div[@class='modal-footer']/button[text()='$translatedButton']";
            $dialogButton = $page->find('xpath', $xpath);
            if (!$dialogButton) {
                throw new \Exception($translatedButton . ' button not found');
            }
            $dialogButton->focus();
            $dialogButton->click();
            return true;
        });
    }

    /**
     * @Then /^I will (not )?see strings: "([^"]*)"$/
     */
    public function iWillSeeStrings($not, $strings)
    {
        $allStrings = preg_split('/\s*,\s*/', $strings);
        if (!$allStrings[0] xor $not) {
            $this->iWillNotSee($allStrings[0]);
        } else {
            $this->iWillSee($allStrings[0]);
        }
        $cnt = count($allStrings);
        for ($i = 1; $i < $cnt; $i++) {
            $allStrings[$i] = $this->translate($allStrings[$i]);
            if (!$allStrings[$i] xor $not) {
                $this->iShouldAlsoNotSee($allStrings[$i]);
            } else {
                $this->iShouldAlsoSee($allStrings[$i]);
            }
        }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" value in "([^"]*)" filter$/
     * @Given /^I will (not )?see "([^"]*)" value in "([^"]*)" list$/
     */
    public function iWillSeeValueInFilter($not, $optionValue, $filterdName)
    {
        $page = $this->getSession()->getPage();
        $translatedFilterdName = $this->translate($filterdName);
        $this->clickXpath("//label[text()='$translatedFilterdName']/following::div[contains(@class,'field-widget')]//select[contains(@class,'js-selectlist-select')] | //label[text()='$translatedFilterdName']/following::div//select[contains(@class,'js-selectlist-select')]");
        $this->spin(function () use ($page, $not, $optionValue, $translatedFilterdName) {
            $optionName = $page->find('xpath', "//label[text()='$translatedFilterdName']/../following-sibling::div/select/option[text()='$optionValue']");
            if (!$optionName xor $not) {
                throw new \Exception("$optionValue is" . ($not ? ' ' : ' not ') . "found");
            }
            return true;
        });
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" expires in "([^"]*)"$/
     */
    public function iWillSeeJobExpiryDate($not, $job, $date)
    {
        $job = $this->translate($job);
        $date = date("M d, Y", strtotime($date));
        $xpath = "//*[normalize-space(text())='$job']/parent::div/../div//font[contains(.,'$date')]";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$job with Expiry date : $date" . ($not ? ' ' : ' not ') . "found");
        }
    }

    public function useButton($label)
    {
        $label = $this->translate($label);
        $signInBtn = $this->translate('Sign In');
        if ($label !== $signInBtn) {
            parent::useButton($label);
        } else {
            $this->pressButton($label);
            $this->iWillNotSee('You are all set!');
            if ($this->getActiveInterface() === 'students') {
                $this->iCompleteOnBoardingProcess();
            }
            return true;
        }
    }

    /**
     * @Then I select :option option from :value in :employer employer
     */
    public function iSelectOptionFromInEmployer($option, $value, $employer)
    {
        $page = $this->getSession()->getPage();
        $this->getSession()->executeScript('window.scrollTo(700,1000);');
        $this->iWillSee("Bidding");
        $page->find('xpath', "//td[@data-th='Employer']//div[text()[normalize-space()='$employer']]/../following-sibling::td[@data-th='Bidding']//div[contains(@class,'status_nobid')]//select[contains(@class,'js-selectlist-select') and @name='$value']")->click();
        $this->spin(function () use ($page, $employer, $value, $option) {
            $xpath = "//td[@data-th='Employer']//div[text()[normalize-space()='$employer']]/../following-sibling::td[@data-th='Bidding']//div[contains(@class,'status_nobid')]//select[contains(@class,'js-selectlist-select') and @name='$value']//option[text()='$option']";
            $optionValue = $page->find('xpath', $xpath);
            if (!$optionValue) {
                throw new \Exception($option . ' option not found');
            }
            $optionValue->focus();
            $optionValue->click();
            return true;
        });
    }

    /**
     * @When I apply these list filters:
     */
    public function iApplyTheseListFilters(TableNode $table)
    {
        $page = $this->getSession()->getPage();
        $more_filters_button = $page->findById('toggle-hidden-filters');
        if ($more_filters_button && ($more_filters_button->getValue() === $this->translate('More Filters'))) {
            $this->iClickLink([
                'link_object' => $more_filters_button,
                'link_label' => $this->translate('More Filters')
            ]);
        }
        $this->iFillFields($table);
        $this->iClickToButton("apply search");
    }

    /**
     * @When I delete :Organization Organizations and Activities
     */
    public function iDeleteOrganizationsAndActivities($organizaton)
    {
        $page = $this->getSession()->getPage();
        $translateDelete = $this->translate('Delete');
        $activityExists = $page->find('css', "div[id^=view-activities]");
        if ($activityExists) {
            $this->iWillSee($organizaton);
            $editXpath = "//div[@id='profile-activities']//md-icon[text()='edit']/following-sibling::p[text()='$organizaton']";
            $this->focusAndClick($editXpath);
            $this->iWillSeeButton(null, 'Delete');
            $this->spin(function () use ($page, $translateDelete, $activityExists) {
                $deleteXpath = "//div[contains(@class,'form-action layout-wrap')]//button[text()='$translateDelete']";
                $deleteButton = $page->find('xpath', $deleteXpath);
                if ($deleteButton) {
                    $this->iMouseOverSection($translateDelete);
                    $deleteButton->focus();
                    $deleteButton->click();
                    $this->iShouldSeeInDialogbox(null, 'Discard this entry');
                    $this->iWillSee('Are you sure you want to delete this item?');
                    $this->iWillSeeButton(null, 'Discard');
                    $this->iClickButtonInDialogbox('Discard');
                    $this->iWillSee('Successfully Deleted');
                }
                return true;
            });
        } else {
            throw new \Exception('Organizations and Activities not exists');
        }
    }

    /**
     * @Given /^I will (not )?see Application Deadline as "([^"]*)"$/
     */
    public function iWillSeeApplicationDeadline($not, $value)
    {
        $this->iWillSeeLoadingIndicator();
        $stringSplit = preg_match_all("/\\[(.*?)\\]/", $value, $matches);
        $deadLine = $matches[1][0];
        $convertedDeadLine = $this->iConvertIntoDate($deadLine);
        $updatedDeadline = preg_replace('/\\[(.*?)\\]/', $convertedDeadLine, $value, 1);
        if ($stringSplit === 1) {
            $updatedInterviewDate = $updatedDeadline;
        } else {
            $interViewDate = $matches[1][1];
            $convertedInterviewDate = $this->iConvertIntoDate($interViewDate);
            $updatedInterviewDate = preg_replace('/\\[(.*?)\\]/', $convertedInterviewDate, $updatedDeadline, 2);
        }
        $xpath = "//p[contains(text(),'$updatedInterviewDate')]";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$updatedInterviewDate : " . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Then /^I will see "([^"]*)" timeslot is ([^"]*) in the "([^"]*)" selector$/
     */
    public function iWillSeeTimeslotIsDisabled($time, $value, $interview)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//label[contains(text(),'$interview')]/following::div[@class='field-widget ng-star-inserted']";
        $this->clickXpath($xpath);
        $interviewTime = $page->find('xpath', "//*[@disabled and contains(text(), '$time')]");
        if (!$interviewTime && $value === 'enabled') {
            $this->ifEnabled($time);
        } elseif ($interviewTime && $value === 'disabled') {
            $this->ifDisabled($time);
        }
    }

    /**
     * @When I will click on active :button button and see :text
     */
    public function iWillClickOnActiveButtonAndSee($button, $text)
    {
        $this->iWillClickOnActiveButton($button);
        try {
            $this->iWillSee($text);
        } catch (\Exception $e) {
            $this->iWillClickOnActiveButton($button);
            $this->iWillSee($text);
        }
    }

    /**
     * @Then I will click on active :button button and not see :text
     */
    public function iWillClickOnActiveButtonAndNotSee($button, $text)
    {
        $this->iWillClickOnActiveButton($button);
        try {
            $this->iWillNotSee($text);
        } catch (\Exception $e) {
            $this->iWillClickOnActiveButton($button);
            $this->iWillNotSee($text);
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" link for "([^"]*)"$/
     */
    public function iWillSeeLinkFor($not, $link, $record)
    {
        $link = $this->translate($link);
        $xpath = "//div[@class='list-item-title']//following::*[normalize-space(text())='$record']/ancestor::div[@class='list-item-body']//following-sibling::div/a/span[text()='$link'] | //div[text()='$record']//preceding::div[@class='flex-row margin-bottom-md']//following::a[text()='$link'] | //div[@class='field-label']/span[text()='$record']/ancestor::div[@class='field ']/div[contains(@class, 'widget-readonly')]/a[text()='$link']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$link link for $record" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
    * @Given I navigate to recently visited navigation :value
    */
    public function iNavigateToRecentlyVisitedNavigation($value)
    {
        $translatedValue = $this->translate($value);
        $xpath = "//h4[text()='RECENTLY VISITED']//ancestor::ul/ul[1]//span[text()='$translatedValue']";
        $this->focusAndClick($xpath);
    }

    /**
     * @Given /^I will (not )?see initial "([^"]*)" for "([^"]*)" ([^"]*)$/
     */
    public function iWillSeeInitialForContact($not, $intial, $empName, $userType)
    {
        $xpath = "//*[text()='$empName']/following::*[text()='$userType']/ancestor::*[@class='list-item padding-y-lg neg-margin-x-lg']//*[@class='avatar monogram' and normalize-space(text())='$intial']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$intial for $empName the $userType" . ($not ? ' ' : ' not ') . "found");
        }
    }

     /**
     * @Given /^I will (not )?see logo for "([^"]*)" ([^"]*)$/
     */
    public function iWillSeeLogoForFaculty($not, $empName, $userType)
    {
        $xpath = "//*[text()='$empName']/following::*[text()='$userType']/ancestor::*[@class='list-item padding-y-lg neg-margin-x-lg']//*[@class='avatar monogram' and img[@alt='$empName']]";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("Logo for $empName the $userType" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Given /^I will (not )?see ([^"]*) as "([^"]*)" for "([^"]*)"$/
     */
    public function iWillSeeDeadlineAsFor($not, $field, $comment, $userName)
    {
        $xpath = "//ancestor::*[@class='sidebar-body']//*[normalize-space(text())='$userName']/following::*[normalize-space(text())='$field'][1]/following::*[normalize-space(text())='$comment'][1]";
        $this->scrollToElement($xpath);
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$comment for $field the $userName" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" ([^"]*) as "([^"]*)" under the List of Approvers$/
     */
    public function iWillSeeContactAsUnderTheListOfApprovers($not, $userName, $field_type, $result)
    {
        $xpath = "//*[normalize-space(text())='$userName']/following::*[normalize-space(text())='$field_type']/ancestor::*[@class='list-item padding-y-lg neg-margin-x-lg' or @class='sidebar-list-item-content']//following-sibling::*[text()='$result']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$userName as $result" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Given /^I select the option "([^"]*)" for "([^"]*)"$/
     */
    public function iSelectOptionFor($option, $stepAndActivity)
    {
        [$step, $activity] = explode('>', $stepAndActivity);
        $this->clickXpath("//div[text()='$step']/ancestor::ul//span[text()='$activity']/ancestor::div[@class='pathway-task-title']//i[@class='icn-more_vertical']");
        $this->iWillSee("$option");
        $this->clickXpath("//div[text()='$step']/ancestor::ul//span[text()='$activity']/ancestor::div[@class='pathway-task-title']//i[@class='icn-more_vertical']/../parent::div//span[text()='$option']");
    }

    /**
     * @Given I click button :buttonLabel and move to next page
     */
    public function iClickButtonAndMovetoNextPage($buttonLabel)
    {
        $urlBeforeButtonClick = $this->getSession()->getCurrentUrl();
        $this->iClickToButton($buttonLabel);
        $urlAfterButtonClick = $this->getSession()->getCurrentUrl();
        if ($urlBeforeButtonClick === $urlAfterButtonClick) {
            try {
                $this->iClickToButton($buttonLabel);
            } catch (\Exception $e) {
                //probably button processed on first click and navigated to next page
            }
        }
    }

    /**
    * @Then /^I will (not )?see option "([^"]*)" in "([^"]*)" field for "([^"]*)"$/
    */
    public function iWillSeeOptionInFieldFor($not, $option, $field, $value = null)
    {
        if ($value) {
            if (strpos($value, 'for')) {
                [$value, $updatedvalue] = explode('for', $value);
                $xpath = "//span[contains(text(),'" . trim($updatedvalue) . "')]/following::label[contains(text(),'$field')][1]/parent::div/select/option[contains(text(),'$option')]";
            } else {
                $xpath = "//span[contains(text(),'$value')]/following::label[contains(text(),'$field')][1]/parent::div/select/option[contains(text(),'$option')]";
            }
            if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
                throw new \Exception("$field with $option" . ($not ? ' ' : ' not ') . "found");
            }
        }
    }

    /**
     * @Then /^I will (not )?see default option "([^"]*)" selected in "([^"]*)" field for "([^"]*)"$/
     */
    public function iWillSeeDefaultOptionInFieldFor($not, $option, $field, $value = null)
    {
        if ($value) {
            if (strpos($value, 'for')) {
                [$value, $updatedvalue] = explode('for', $value);
                $xpath = "//span[contains(text(),'" . trim($updatedvalue) . "')]/following::label[contains(text(),'$field')][1]/parent::div/select/option[contains(text(),'$option') and @selected='selected']";
            } else {
                $xpath = "//span[contains(text(),'$value')]/following::label[contains(text(),'$field')][1]/parent::div/select/option[contains(text(),'$option') and @selected='selected']";
            }
            if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
                throw new \Exception("$field with $option" . ($not ? ' ' : ' not ') . "selected");
            }
        }
    }

    /**
     * @When /^I log out and go to (\w+) interface$/
     */
    public function iLogOutAndGoTo(string $interface)
    {
        $this->iAmLoggedOut();
        $this->iGoToInterface($interface);
    }

    /**
     * @Then /^I click "([^"]*)" next to "([^"]*)" under "([^"]*)"$/
     */
    public function iClickNextToUnderRegistration($result, $empName, $secName)
    {
        $page = $this->getSession()->getPage();
        $page->find('xpath', "//div[@class='sidebar-title']//h6[text()='" . $this->translate($secName) . "']/following::*[contains(text(), '$empName')]/ancestor::div[contains(@class,'sidebar-body-list')]//following::span[text()='" . $this->translate($result) . "']");
        $this->spin(function () use ($page, $empName, $result, $secName) {
            $xpath = "//div[@class='sidebar-title']//h2[text()='" . $this->translate($secName) . "']/following::*[contains(text(), '$empName')]/ancestor::div[contains(@class,'sidebar-body-list')]//following::span[text()='" . $this->translate($result) . "']";
            $resultValue = $page->find('xpath', $xpath);
            if (!$resultValue) {
                throw new \Exception("$result under the $empName" . ' not found');
            }
            $resultValue->focus();
            $resultValue->click();
            return true;
        });
    }

    /**
     * @When /^I click on "([^"]*)" button for "([^"]*)" under "([^"]*)" user$/
     */
    public function clickOnButtonForUnderUser($override, $userType, $userName)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//div[text()='$userName']/following::div/div[text()='$userType']/following::div/button[contains(@aria-label,'$override')]";
        $overrideButton = $page->find('xpath', $xpath);
        if ($overrideButton !== null) {
            $overrideButton->focus();
            $overrideButton->click();
        } else {
            throw new \Exception('Override button not found for ' . $userType . '=>' . $userName);
        }
    }

    /**
     * @Given /^I will (not )?see result "([^"]*)" as "([^"]*)" in table$/
     */
    public function iWillSeeResultAsInTable($not, $column, $value)
    {
        $translatedColumn = $this->translate($column);
        $translatedValue = $this->translate($value);
        if ($column === "Attached Document") {
            $translatedColumn = str_replace('<br>', '', $this->translate('Attached<br>Document'));
        }
        if (preg_match('/\bmatch found\b/', $value) || preg_match('/\bmatches found\b/', $value) && (in_array(self::$tag, ['PT-CSM', 'AR-CSM', 'ES-CSM']))) {
            $valueSplit = explode(' ', $value);
            $count = (int) $valueSplit[0];
            $string = $this->translate("{count, plural, =1{1 match} other{# matches}} found");
            if (self::$tag === 'AR-CSM') {
                $split = explode('}', $string);
                $split2 = explode('{', $split[0]);
                $split3 = explode('#', $split[1]);
                if ($count === 1) {
                    $translatedValue = $split2[0] . $split2[2];
                } elseif ($count > 1) {
                    $arabic_eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
                    $arabic_western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
                    $arb_count = str_replace($arabic_western, $arabic_eastern, $count);
                    $translatedValue = $split2[0] . $arb_count . $split3[1];}
            }
            if ((in_array(self::$tag, ['PT-CSM', 'ES-CSM']))) {
                $getString = strrchr($string, "1");
                $split = explode('}', $getString);
                $split2 = explode('#', $split[1]);
                if ($count === 1) {
                    $translatedValue = $split[0];
                } elseif ($count > 1) {
                    $translatedValue = $count . $split2[1];
                }
            }
        }
        if ($value === "Arlington" && self::$tag === "AR-CSM") {
            $translatedValue = "أرلينغتون";
        }
        $xpath = "//span[text()='$translatedColumn']/ancestor::table/tbody/tr//*[contains(text(),'$translatedValue')] | //span[normalize-space()='$translatedColumn']/ancestor::table/tbody/tr//*[contains(@title, '$translatedValue')]";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception('Value ' . $translatedValue . ' was ' . ($not ? '' : 'not ') . 'found for ' . $translatedColumn);
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" button for "([^"]*)" chart$/
     */
    public function iWillSeeButtonForChart($not, $repButton, $chartName)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//span[normalize-space(text())='$chartName']/ancestor::div[@class='chart-summary-wrap']/div/div//*[normalize-space(text())='$repButton']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception('Button' . $repButton . ' is ' . ($not ? 'found' : 'not found') . ' in the ' . $chartName  . ' chart ');
        }
    }

    /**
     * @Then /^I should (not )?see result for "([^"]*)" as "([^"]*)" date$/
     */
    public function iShouldSeeDateResult($not, $colmnName, $date)
    {
        $page = $this->getSession()->getPage();
        $translatedcolmnName = $this->translate($colmnName);
        $getFirstDate = date("M d, Y", strtotime($date));
        $getSecondDate = date("M j, Y", strtotime($date));
        $getThiredDate = date("M jS", strtotime($date));
        if ($this->getActiveInterface() === 'employers') {
            $xpath = "//div[@class='list-item-title']//a[@class='ListPrimaryLink' and text()='$colmnName - $getThiredDate'] | //div[@class='list-item-title-container' and normalize-space(text())='" .date("F d, Y", strtotime($date)). ": $colmnName' or normalize-space(text())='" .date("F j, Y", strtotime($date)). ": $colmnName']";
        } else {
			$xpath = "//table/following::tr/th/following::*[text()='$translatedcolmnName']/ancestor::table/tbody/tr/td/div[contains(text(),'$getFirstDate') or contains(text(),'$getSecondDate') or contains(text(),'$getThiredDate')] | //table/tbody/tr/th[text()='$translatedcolmnName']/following-sibling::td[contains(text(),'$getFirstDate') or contains(text(),'$getSecondDate') or contains(text(),'$getThiredDate')]";
        }
        $element = $page->find('xpath', $xpath);
        if (!$element xor $not) {
            try {
                $this->scrollToElement($xpath);
            } catch (\Exception $e) {
                    throw new \Exception('Value ' . $date . ' was ' . ($not ? '' : 'not ') . 'found for ' . $translatedcolmnName);
            }
        }
        if ($element xor $not) {
            return true;
        } else {
            throw new \Exception('Value ' . $date . ' was ' . ($not ? '' : 'not ') . 'found for ' . $translatedcolmnName);       
        }
    }

    /**
     * @Then /^I will (not )?see richtext toolbar icons:$/
     */
    public function iWillSeeRichtextToolbarIcons($not = null, TableNode $table = null)
    {
        $page = $this->getSession()->getPage();
        foreach ($table->gethash() as $tool) {
            $xpath = "//" . $tool['type'] . "[@aria-label='" . $tool['aria-label'] . "']";
            if (!$page->find('xpath', $xpath) xor $not) {
                throw new \Exception("Element " . $tool['type'] . ' '. $tool['aria-label'] . ($not ? ' ' : ' not ') . "found");
            }
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" in richtext editor for "([^"]*)"$/
     */
    public function iWillSeeInRichtextEditorFor($not, $text, $field)
    {
        $iFrameXpath = "//label[normalize-space(text())='$field']/following::iframe[1]";
        $iFrame = $this->findXpathElement($iFrameXpath);
        if ($iFrame && $iFrame->hasAttribute('id')) {
            $iFrameId = $iFrame->getAttribute('id');
            $this->getSession()->getDriver()->switchToIFrame($iFrameId);
            $page = $this->getSession()->getPage();
            if (!$not) {
                $this->iWillSee($text);
            } else {
                $this->iWillNotSee($text);
            }
        }
        $this->switchToMainFrame();
    }

    /**
     * @Then I click :back link for :titleBar on the title bar
     */
    public function iClickLinkForOnTheTitleBar($back, $titleBar)
    {
        $page = $this->getSession()->getPage();
        $backXpath = "//div[@class='titlebar']/*[contains(text(), '$titleBar')]/following::div[@class='back']/a[text()='" . $this->translate($back) . "']";
        $backLink = $page->find('xpath', $backXpath);
        if ($backLink) {
            $backLink->click();
        }
    }

    /**
     * @Then /^I will (not )?see logo for "([^"]*)" with "([^"]*)" job$/
     */
    public function iWillSeeLogoForWithJob($not, $orgName, $jobName)
    {
        $xpath = "//div[@class='list-item-title']//following::*[normalize-space(text())='$jobName']/ancestor::div[@class='list-item-body']//following-sibling::div/p/a[text()='$orgName']/ancestor::li[contains(@class, 'list-item list')]/div/div[contains(@class, avatar-)]/a/img[@alt='Logo']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("Logo for $orgName with $jobName" . ($not ? ' ' : ' not ') . "found");
        }
    }
    
    /**
     * @Then /^I will (not )?see "([^"]*)" for "([^"]*)" job$/
     */
    public function iWillSeeForJob($not, $comment, $jobName)
    {
        $xpath = "//*[contains(normalize-space(text()), '$jobName')]/ancestor::tr[@class='row_content_style']//following-sibling::*[normalize-space(text())='$comment']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$comment for $jobName job" . ($not ? ' ' : ' not ') . "found");
        }
    }
 
    /**
     * @Then /^I will (not )?see "([^"]*)" initial for "([^"]*)" with "([^"]*)" job$/
     */
    public function iWillSeeInitialForWithJob($not, $initial, $orgName, $jobName)
    {
        $xpath = "//div[@class='list-item-title']//following::*[normalize-space(text())='$jobName']/ancestor::div[@class='list-item-body']//following-sibling::div/p/a[text()='$orgName']/ancestor::li[contains(@class, 'list-item list')]/div/div[(contains(@class, avatar-)) and normalize-space(text())='$initial']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$initial initial for $orgName with $jobName job" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" as "([^"]*)" on flyover view$/
     */
    public function iWillSeeAsOnFlyoverView($not, $search, $text)
    {
        $page = $this->getSession()->getPage();
        $translatedText = $this->translate($text);
        $this->skeletonLoader();
        if ($search === 'Job title') {
            $xpath = $page->find('xpath', "//h1[contains(@class,'text-bold ng-tns-') and text() = '$translatedText']");
        } elseif ( $search === 'Contact info') {
                $xpath = $page->find('xpath', "//div[contains(@class,'flex-row align-items-end justify-content-between ng-tns-') or contains(@class,'hide-sticky margin-top-sm ng-tns-')]/span[contains(@class,'ng-tns-') and contains(text(),'$translatedText')]");
            } elseif ($search === 'Administrative info') {
                    $xpath = $page->find('xpath', "//div[contains(@class,'adm-info padding-md border-bottom text-gray ng-tns-')]/span[contains(@class,'ng-tns-') and contains(text(),'$translatedText')]");
                } else {
                    $field = $page->find('xpath', "//div[@class='field-label']/label[text()='" . $this->translate($search) . "']");
                    if ($field) {
                        $xpath = $page->find('xpath', "//div[@class='text-truncate' and text()='$translatedText']");
                    }
                }
        if (!$xpath xor $not) {
            throw new \Exception('The ' . $translatedText . ' was ' . ($not ? '' : 'not ') . 'found for ' . $this->translate($search) . ' section');
        }
    }

    private function skeletonLoader()
    {
        $page = $this->getSession()->getPage();
        $skeletonLoader = "//div[contains(@class,'form-container ng-tns-')]//ngx-skeleton-loader";
        if ($skeletonLoader) {
            $this->spin(static function () use ($page, $skeletonLoader) {
                $skeletonLoaderObj = $page->find('xpath', "$skeletonLoader");
                if (!$skeletonLoaderObj) {
                    return true;
                }
                throw new \Exception('Flyover loading continously');
        });
        }
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" field on the flyover view$/
     */
    public function iShouldSeefieldname($not, $field)
    {
        $page = $this->getSession()->getPage();
        $translatedField = $this->translate($field);
        $this->skeletonLoader();
        $fieldText = $page->find('xpath', "//*[(contains(@id,'sy_formfield') or contains(@for,'sy_formfield')) and normalize-space(text())='$translatedField'] | //div[contains(@class,'field-label-readonly')]/span[normalize-space(text())='$translatedField']");
        if (!$fieldText xor $not) {
            throw new \Exception('The ' . $translatedField . ' field was ' . ($not ? '' : 'not ') . 'found on the flyover view ');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" pill is "([^"]*)" color under "([^"]*)" section$/
     */
    public function iWillSeePillIsColorUnderSection($not, $option, $color, $section)
    {
        $page = $this->getSession()->getPage();
        $translateOption = $this->translate($option);
        if (strpos($section, ">") !== false) {
            $splitSection = explode('>', $section);
            $commonXpath = "//*[text()='" . $this->translate(trim($splitSection[0])) . "']/parent::div/descendant::*[contains(@class, 'job-details-app-form')]//span[contains(normalize-space(text()), '" . $this->translate(trim($splitSection[1])) . "')]";
            if ($color === 'Green') {
                $xpath = "$commonXpath/parent::div/following-sibling::div/*[contains(@class, 'chip-sm chip-success ng-star-inserted')]/span[text()='$translateOption']";
            } elseif ($color === 'Gray') {
                $xpath = "$commonXpath/parent::div/following-sibling::div/*[contains(@class, 'chip-disabled chip-sm ng-star-inserted')]/span[text()='$translateOption']";
            }
            if ((!$page->find('xpath', $xpath)) xor $not) {
                throw new \Exception($option . ' pill is ' . ($not ? ' ' : ' not ') . ' in ' . $color . ' color under ' . $section);
            }
        } else {
            $splitOption = preg_split('/\s*,\s*/', $option);
            $cnt = count($splitOption);
            for ($i = 0; $i < $cnt; $i++) {
                $xpath = "//h3[text()='" . $this->translate($section) . "']/ancestor::div/descendant::span[@class='chip chip-sm chip-success ng-star-inserted']/span[text()='$splitOption[$i]']";
                if ((!$page->find('xpath', $xpath)) xor $not) {
                    throw new \Exception($splitOption[$i] . ' pill is ' . ($not ? ' ' : ' not ') . ' in ' . $color . ' color under ' . $section);
                }
            }
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" section below "([^"]*)" section$/
     */
    public function iWillSeeSectionUnderSection($not, $secSection, $firSection)
    {
        $xpath = "//*[text()='" . $this->translate($firSection) . "']/parent::div/following::div[1]/*[text()='" . $this->translate($secSection) . "'] | //h2[text()='" . $this->translate($firSection) . "']/ancestor::*[@ng-reflect-data='[object Object]']/following::*[@ng-reflect-data='[object Object]'][1]//h2[text()='" . $this->translate($secSection) . "']";
        if (!$this->getSession()->getPage()->find('xpath', $xpath) xor $not) {
            throw new \Exception("$secSection section below $firSection" . ($not ? ' ' : ' not ') . "found");
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" applicants for the "([^"]*)" position$/
     */
    public function iWillSeeApplicantsForThePosition($not, $count, $position)
    {
        $page = $this->getSession()->getPage();
        $xpath = $page->find('xpath', "//a[text()='$position']/ancestor::div/following-sibling::*[@class='list-item-actions']/div/span[normalize-space(text()='applicant')]/parent::div[contains(@class, 'appcount')]/*[@class='badge-count' and text()='$count']");
        if (!$xpath xor $not) {
            throw new \Exception($count . ' applicants is ' . ($not ? '' : 'not ') . ' found for the ' . $position);
        }
    }

    /**
     * @When /^I will (not )?see "([^"]*)" for "([^"]*)" position$/
     */
    public function iWillSeeForPosition($not, $status, $position)
    {
        $page = $this->getSession()->getPage();
        if (preg_match("/\\[(.*?)\\]/", $status, $value)) {
            $statusSplit = trim(str_replace($value[0], '', $status));
            $translateSplitStatus = $this->translate($statusSplit);
            $date = date('M d, Y', strtotime($value[1]));
            $translateDate = $this->translateDate($date, $status);
            $translateStatus = "$translateSplitStatus $translateDate";
        } else {
            $translateStatus = $this->translate($status);
        }
        $updatedStatus = $page->find('xpath', "//*[normalize-space(text())='$position']/parent::div/../div/following-sibling::div[@class='list-data-columns' or @class='list-item-subtitle']/*[text()[normalize-space()=" . '"' . $translateStatus . '"' . "]]");
        if (!$updatedStatus xor $not) {
            throw new \Exception($status . ($not ? '' : ' not ') .' found for ' . $position . ' position');
        }
    }

    /**
     * @When /^I should (not )?see "([^"]*)" as section header$/
     */
    public function iShouldSeeSectionHeader($not, $section)
    {
        $this->spin(function () use ($section, $not) {
            if (!$this->getSession()->getPage()->find('xpath', "//h2[text()='$section'] | //h3[text()='$section'] | //div[contains(@class, 'sidebar-title')][text()[normalize-space()='$section']]") xor $not) {
                throw new \Exception($section . ' is ' . ($not ? '' : ' not ') .' in section header');
            }
            return true;
        });
    }

    /**
     * @Then /^I should (not )?see "([^"]*)" with "([^"]*)" under tasks section$/
     */
    public function iShouldSeeUnderTaskSection($not, $text, $format)
    {
        $page = $this->getSession()->getPage();
        $translatedText = $this->translate($text);
        if ($format === 'Strong') {
            $xpath = $page->find('xpath', "//strong[text()='$translatedText'] | //b[text()='$translatedText']");
        } elseif ($format === 'Italic') {
            $xpath = $page->find('xpath', "//em[text()='$translatedText'] | //i[text()='$translatedText']");
        } elseif ($format === 'Strikethrough') {
            $xpath = $page->find('xpath', "//s[text()='$translatedText'] | //span[contains(@class, 'text-decoration-line-through')]/strong[text()='$translatedText'] | //del[text()='$translatedText'] | //span[contains(@style, 'line-through') and text()='$translatedText']");
        }
        if (!$xpath xor $not) {
            throw new \Exception('The ' . $translatedText . ' is ' . ($not ? '' : 'not ') . ' in ' . $format);
        } 
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" for "([^"]*)" tile$/
    */
    public function iWillSeeForTile($not, $tileText, $tileName)
    {
        $page = $this->getSession()->getPage();
        $translatedTileName = $this->translate($tileName);
        $xpath = "//a[contains(@class, 'tile-layout')]/div[@class='tile-body']/h2[text()='$translatedTileName']/following-sibling::div[contains(@class, 'tile-text') and text()='$tileText']";
        if (!$page->find('xpath', $xpath) xor $not) {
                throw new \Exception($tileText . ' is ' . ($not ? : 'not found') . ' for the ' . $translatedTileName  . ' tile ');
        }
    }

    /**
     * @Given /^I will (not )?see tile title "([^"]*)" below "([^"]*)" icon$/
    */
    public function iWillSeeTitleIcon($not, $tileTitle, $tileIcon)
    {
        $page = $this->getSession()->getPage();
        $translatedTileTitle = $this->translate($tileTitle);
        $xpath = "//a[contains(@class, 'tile-layout')]/div[@class='avatar primary']/i[@class='icn-". strtolower($tileIcon) ."' or @class='icn-user_". strtolower($tileIcon) ."']/following::div[@class='tile-body'][1]/h2[@class='tile-title' and text()='$translatedTileTitle']";
        if (!$page->find('xpath', $xpath) xor $not) {
                throw new \Exception($translatedTileTitle . ' title is ' . ($not ? : 'not found') . ' below the ' . $tileIcon  . ' icon ');
        }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" icon on "([^"]*)" tile$/
     */
    public function iWillSeeIconOnTile($not, $tileIcon, $tileName)
    {
        $page = $this->getSession()->getPage();
        $translatedTileName = $this->translate($tileName);
        $xpath = "//a[contains(@class, 'tile-layout')]/div[@class='tile-body']/h2[text()='$translatedTileName']/preceding::div[@class='avatar primary'][1]/i[@class='icn-". strtolower($tileIcon) ."' or @class='icn-user_". strtolower($tileIcon) ."']";
        if (!$page->find('xpath', $xpath) xor $not) {
                throw new \Exception('Icon ' . $tileIcon . ' is ' . ($not ? : 'not found') . ' in the ' . $translatedTileName  . ' tile ');
        }
    }
    
    /**
     * @When I open :card card and see :text
     */
    public function iOpenCardAndSee($card, $text)
    {
        $this->iOpenCard($card);
        $this->iWillSee($text);
    }

    /**
     * @When /^I will (not )?see "([^"]*)" option in user menu$/
     */
    public function iWillSeeOptionInUserMenu($not, $option)
    {
        $page = $this->getSession()->getPage();
        $translateOption = $this->translate($option);
        $xpath= "//div[@id='user-avatar']//button[contains(@aria-label,'User Menu')]/following-sibling::div[@aria-label='User Account and Tools links']/ul/li/a[normalize-space(text())='$translateOption']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception('Option ' . $translateOption . ' is ' . ($not ? ' found ' : 'not found ') . ' in the user menu');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" field contains the "([^"]*)" text$/
     */
    public function iWillSeeTruncateText($not, $field, $text)
    {
        $page = $this->getSession()->getPage();
        $split = explode(" ", $field);
        $xpath = "//div[contains(@id,'sy_formfield_" . mb_strtolower($split[0]) . "')]//div[@class='text-truncate' and text()='$text']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception('The field ' . $field . ' was ' . ($not ? '' : 'not ') . 'contains the ' . $text . ' as text' );
        }
    }

    /**
     * @Then I will Accept the Cookie
     */
    public function iWillAcceptTheCookie()
    {
        $page = $this->getSession()->getPage();
        $AcceptCookie = $page->find('xpath', "//a[contains(text(),'Accept')]");
        $this->spin(function () use ($page, $AcceptCookie) {
            if ($AcceptCookie) {
                $this->clickXpath("//a[contains(text(),'Accept')]");
            }
            return true;
        });
    }

    private function iConvertIntoDate($date)
    {
        $currentDay = date('l');
            if (strpos($date, '-') && strpos($date, $currentDay)) {
                $updatedDate = str_replace('Next', 'Next week', $date);
                $date = $updatedDate;
            }
            $date = trim(date("M j", strtotime($date)));
            return $date;
    }
    
    /**
     * @Given /^I will (not )?see "([^"]*)" under "([^"]*)" Section$/
    */
    public function iWillSeeUnderSection($not, $value, $section)
    {
        $page = $this->getSession()->getPage();
        $splitValue = explode(' on ', $value);
        $stringSplit = preg_match_all("/\\[(.*?)\\]/", $value, $matches);
        $dateFirst = $matches[1][0];
        $convertedFirstValue = $this->iConvertIntoDate($dateFirst);
        $updatedFirstDate = preg_replace('/\\[(.*?)\\]/', $convertedFirstValue, $value, 1);
        if ($stringSplit === 1) {
            $xpath = "//h3[normalize-space(text())='$section']/following::div[@class='panel-title']//span[text()='$splitValue[0]']/parent::span/span[contains(@class, 'secondary-text') and contains(text(), '$convertedFirstValue')]";
        } else {
        	$stringSplitSec = $matches[1][1];
            $convertedSecValue = $this->iConvertIntoDate($stringSplitSec);
            $updatedInterviewDate = preg_replace('/\\[(.*?)\\]/', $convertedSecValue, $updatedFirstDate, 2);
            $xpath = "//h3[normalize-space(text())='$section']/following::div[contains(@class, 'space-bottom') and contains(text(), '$updatedInterviewDate')]";
        }
            if (!$page->find('xpath', $xpath) xor $not) {
                throw new \Exception($value . ($not ? '' : ' not ') .' found for ' . $section . ' section');
            }
    }

    /**
     * @Given /^I will (not )?see "([^"]*)" icon for "([^"]*)" file$/
     * @Given /^I will (not )?see "([^"]*)" icon for "([^"]*)"$/
     */
    public function iWillSeeIconForFile($not, $icon, $fileName)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//a[normalize-space(text())='$fileName']/parent::div[contains(@class, 'ng-star-inserted')]/span[contains(@class, 'icn-". strtolower($icon) ."')] | //span[normalize-space(text())='$fileName']/parent::button[contains(@class, 'dropdown-item')]/span[contains(@class, 'icn-". strtolower($icon) ."')]";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception('Icon ' . $icon . ' is ' . ($not ? ' found ' : 'not found') . ' in the ' . $fileName  . ' file');
        }
    }

    /**
     * @Then /^I will (not )?see selected filters: "([^"]*)"$/
     */
    public function iWillSeefilters($not, $filter)
    {
        $page = $this->getSession()->getPage();
        $filters = preg_split('/\s*,\s*/', $filter);
        $cnt = count($filters);
        for ($i = 0; $i < $cnt; $i++) {
            $xpath = $page->find('xpath', "//span[@class='chip-value' and text()='" . $this->translate($filters[$i]) . "']");
            if (!$xpath xor $not) {
                    throw new \Exception('Field ' . $filter[$i] . ' was ' . ($not ? '' : 'not ') . 'found');
            }
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" ([^"]*) selected for "([^"]*)" filter$/
     * @Then /^I will (not )?see "([^"]*)" ([^"]*) selected for "([^"]*)" field$/
     */
    public function iWillSeePositionsSelectedForFilter($not, $count, $options, $filterType)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//span[contains(text(), '" . $this->translate($filterType) . "')]/span[@class='ng-star-inserted' and text()='($count)']";
        if ($filterType === "Academic Entities") {
            $valueElem = $page->findAll('xpath', "//label[text()='$filterType']/./following::div[@class='entity-checked' and not(@style='display: none;')]");
            $value = count($valueElem);
            if (((int) $count !== $value) xor $not) {
                throw new \Exception($value . ' options selected ' . (($not) ? 'not ' : '') . ' for ' . $filterType); 
            }
        } else {
            if ($count === 1) {
                if (!$page->find('xpath', $xpath) xor $not) {
                    throw new \Exception($count . ' ' . $options . ' is ' . ($not ? '' : ' not ') . ' filterd in ' . $filterType . ' field');
                } 
            } else {
                if (!$page->find('xpath', $xpath) xor $not) {
                    throw new \Exception($count . ' ' . $options . ' are ' . ($not ? '' : ' not ') . ' filterd in ' . $filterType . ' field');
                }
            }
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" approver's "([^"]*)" as "([^"]*)" on side bar$/
     */
    public function iWillSeeApproverDetails($not, $approver, $header, $value)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//div[@class='flex-row margin-bottom-md']/div[normalize-space(text()) = '$header']/following-sibling::div[text() = '$value']/preceding::div[normalize-space(text())='Name:']/following::div[text()='$approver'] | //div[@class='flex-row margin-bottom-md']/div[normalize-space(text()) = '$header']/following-sibling::div[text() = '$value']/preceding::div[normalize-space(text())='Name:']/following::a[text()='$approver']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception ('The ' . $header . ' ' . $value . ' was ' . ($not ? '' : 'not ') . 'found for ' . $approver . ' on the sidebar');
        }
    }

    /**
     * @Then /^Job title "([^"]*)" should contains as "([^"]*)" on pending approvers table column$/
     */
    public function pendingApproversListOnColumn($jobTitle, $expectedPendingApprovers)
    {
        $page = $this->getSession()->getPage();
        $className = str_replace(" ","",$jobTitle);
        $approversElements = $page->findAll('xpath', "//div[@class='status_" . mb_strtolower($className) . "' and normalize-space(text())='$jobTitle']/following::td[3] | //div[@class='status_" . mb_strtolower($className) . "']/a[normalize-space(text())='$jobTitle']/following::td[3]");
        $pendingApprovers = "";
        foreach ($approversElements as $label) {
            $pendingApprovers .= $label->getText();
        }
        $this->checkPendingApproverNames($pendingApprovers, $expectedPendingApprovers);
    }

    private function checkPendingApproverNames($pendingApprovers, $expectedPendingApprovers)
    {
        if (strpos($pendingApprovers, ',') !== false) {
            if ($pendingApprovers === $expectedPendingApprovers) {
                return $pendingApprovers;
            } else {
                throw new \Exception ('Pending approvers column has not following approvers ' . $expectedPendingApprovers . ', it has ' . $pendingApprovers . ' ');
            }
        } else {
            throw new \Exception ('Pending approvers '. $pendingApprovers .' has not separated with comma');
        }
    }

    /**
     * @Then I will open :evaluation public URL
    */
    public function iWillOpenPublicURL($evaluation)
    {
        $page = $this->getSession()->getPage();
        $evaluation = strtolower($evaluation);
        $evalParts = explode(" ", $evaluation);
        if ($evalParts[0] === "program") {
            $term = substr($evalParts[0], 0, 4);
        } elseif ($evalParts[1] === "midterm") {
            $term = substr($evalParts[0], 0, 3) . "_" . $evalParts[1];
        } elseif ($evalParts[1] === "final") {
            $term = substr($evalParts[0], 0, 3);
        } else {
            $term = $evalParts[0];
        }
        $hideUrlButton = $page->find('xpath', "//input[@id='exp_" . $term . "_eval_link_button' and @value='Hide URL']");
        if (!$hideUrlButton) {
            $showUrlButton = $page->find('xpath', "//input[@id='exp_" . $term . "_eval_link_button' and @value='Show URL']")->click();
        }
        $urlLink = $page->find('xpath', "//div[@id='exp_" . $term . "_eval_link_box']/textarea")->getText();
        if (!$urlLink) {
            throw new \Exception ("$evaluation has not public URL");
        }
        $this->iAmLoggedOut();
        $this->visit($urlLink);
    }

    /**
     * @Then /I will see "([^"]*)" approver names as "([^"]*)" on side bar$/
     */
     public function approversNames($usertype, $expectedPendingApprovers)
    {
        $page = $this->getSession()->getPage();
        $approversElements = $page->findAll('xpath', "//div[@class='flex-row margin-bottom-md']/div[normalize-space(text()) = 'User Type:']/following-sibling::div[text() = '$usertype']/preceding::div[normalize-space(text())='Name:'][1]//following::div[1]");
        $pendingApprovers = "";
        foreach ($approversElements as $label) {
            $pendingApprovers .= $label->getText();
        }
        $this->checkPendingApproverNames($pendingApprovers, $expectedPendingApprovers);
    }

    /**
     * @Then /^I click "([^"]*)" button under "([^"]*)" column for "([^"]*)"$/
    */
    public function buttonUnderColumn($button, $column, $student)
    {
        $page = $this->getSession()->getPage();
        $className = str_replace(" ","",$student);
        $button = $page->find('xpath', "(//th[@class='cspList_rightbothead']/span[text()='$column']/following::div[@class='status_" . mb_strtolower($className) . "']/a[text()='$student']/following::td/div/input[@value='$button'])[1]");
        if ($button) {
                $button->click();
        } else {
           throw new \Exception ("$button button is not existing on the table");
        }
    }

    /**
     * @Then /^I open menu list for "([^"]*)" and (not )?see "([^"]*)" option$/
     */
    public function iOpenMenuListForAndSeeOption($positionName, $not, $menuList)
    {
        $page = $this->getSession()->getPage();
        $translateMenuList = $this->translate($menuList);
        $this->clickXpath("//li[contains(.,'$positionName')]/child::div[@class='list-item-actions']//div[contains(@class,'-toggle')]/child::*[contains(@class,'-toggle')]");
        $xpath = "//li[contains(.,'$positionName')]/child::div[@class='list-item-actions']/div/div/div[contains(@class, 'actions-menu')]//a[normalize-space(text())='$translateMenuList' or normalize-space(text())='" . strtolower($translateMenuList) . "']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception($translateMenuList . ' option is ' . ($not ? : 'not found') . ' for the ' . $positionName  . ' menu list');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" button before the "([^"]*)" button$/
    */
    public function iWillSeeButtonBeforeTheButton($not, $button1, $button2)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//*[(@type='button') and (text()='$button2' or @value='$button2' or @value='" . strtolower($button2) . "')]/preceding::*[(@type='button') and (text()='$button1' or @value = '$button1' or @value='" . strtolower($button1) . "')]";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception ('The ' . $button1 . ' was ' . ($not ? '' : 'not ') . 'found before ' . $button2 . ' on the page');
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" option for "([^"]*)" field$/
     */
    public function iWillSeeOptionForField($not, $option, $field)
    {
        $page = $this->getSession()->getPage();
        $xpath = "//span[normalize-space(text())='$field']/following::div[contains(@class,'field-widget')][1]//label[normalize-space(text())='$option' or normalize-space(text())='" . strtolower($option) . "']";
        if (!$page->find('xpath', $xpath) xor $not) {
            throw new \Exception($option . ' option ' . ($not ? ' is ' : ' is not ') . ' found for the ' . $field  . ' field');
        }
    }

    /**
     * @Then I switch to job board iFrame
     */
    public function iSwitchToJobBoardiFrame()
    {
        $iframeElement = $this->getSession()->getPage()->find('xpath', "//iframe[contains(@title,'Job Board')]");
        $title = $iframeElement->getAttribute('title');
        if (in_array($title, ['Sanity Stage Job Board', 'CSM Sanity Test Site Job Board', 'Sanity Law Stage Job Board', 'Sanity Law Test Job Board'])) {
            $this->switchToIFrame($title);
        }
    }

    /**
     * @When I click on :button button for :name under :section section
    */
    public function iClickOnButtonForUnderSec($button, $name, $section)
    {
        $page = $this->getSession()->getPage();
        $translateSection = $this->translate($section);
        $xpath = $page->find('xpath', "//h2[text()='$translateSection']/following::div/span[text()='$name']");
        if ($xpath) {
            $this->clickxpath("//h2[text()='$translateSection']/following::div/span[text()='$name']/ancestor::div[@class='list-item-body ng-star-inserted']/following-sibling::div[contains(@class, 'list-item-actions')]/div/div/a/span[text()='$button']");
        } else {
            throw new \Exception("$name is not found under $section");
        }
    }

    /**
     * @When I find :announcement announcement on the page
    */
    public function iFindAnnouncement($record)
    {
        $page = $this->getSession()->getPage();
        $announcement = $page->find('xpath', "//div[@class='field-widget-tinymce announcements-home-list']//div[@class='inline list-item-title ng-star-inserted']/span[text()='$record']");
        if ($announcement) {
            $this->iWillSee($record); 
        } else {
            $this->iClickToButton("Show more Announcements");
            $this->iWillSee($record);
        }
    }

    /**
     * @When  I will set :name column in :sort order
    */
    public function iSortColumn($columnName, $sort)
    {
        $page = $this->getSession()->getPage();
        $columnName = $page->find('xpath', "//span[@class='sort-label' and text()='$columnName']");
        $activeCol = $columnName->find('xpath', "/ancestor::th")->getAttribute('class');
        if ($sort === 'Decending') {
            $columnName->click();
            if (!preg_match('/\bactive_col\b/', $activeCol)) {
                    $columnName->click();
            }
            $desc = $columnName->find('xpath', "/ancestor::a[@class='sort_desc']");
            if (!$desc) {
                throw new \Exception('The ' . $columnName . 'column is not in ' . $sort . 'order');
            }
        } else {
            if (!preg_match('/\bactive_col\b/', $activeCol)) {
                $columnName->click();
            }
            $asc = $columnName->find('xpath', "/ancestor::a[@class='sort_asc']");
            if (!$asc) {
                throw new \Exception('The ' . $columnName . 'column is not in ' . $sort . 'order');
            }
        }
    }

    public function translate($key, $subkey = 'misc')
    {
        static $translatedValues = [
            'AR-CSM' => [
                'Manager Job Blast (version 2)' => 'بريد توظيفي سريع للمدير (الإصدار 2)',
                '(0 items selected)' => '(0 عناصر محددة)'
            ],
            'ES-CSM' => [
                'Manager Job Blast (version 2)' => 'Correo electrónico de difusión de empleos del administrador',
                '(0 items selected)' => '(0 elementos seleccionados)'
            ],
            'PT-CSM' => [
                'Manager Job Blast (version 2)' => 'Gerente de Boletim de emprego (versão 2)',
                '(0 items selected)' => '(0 itens selecionados)'
            ]
        ];
        if (isset($translatedValues[self::$tag][$key])) {
            $key = $translatedValues[self::$tag][$key];
            return $key;
        } else {
            return parent::translate($key, $subkey);
        }
    }

    /**
     * @Then /^I will (not )?see "([^"]*)" entities for "([^"]*)" field$/
     */
    public function iWillSeeEntitiesForField($not, $entities, $field)
    {
        $page = $this->getSession()->getPage();
        $entitiesList = preg_split('/\s*,\s*/', $entities);
        $cnt = count($entitiesList);
        for ($i = 0; $i < $cnt; $i++) {
            $xpath = $page->find('xpath', "//label[text()='$field']/./following::div[@class='entity-checked' and text()='$entitiesList[$i]' and not(@style='display: none;')]");
            if (!$xpath xor $not) {
                    throw new \Exception('Entity ' . $entitiesList[$i] . ' was ' . ($not ? '' : 'not ') . 'found for field ' . $field);
            }
        }
    }

    /**
    * @When /^I should see the order as "([^"]*)"$/
    */
    public function iShouldSeeTheOrderAs($record)
    {
        $page = $this->getSession()->getPage();
        $approverRecords = explode(',', $record);
        $approverList = [];
        $xpath = "//div[@class='sidebar-inner sb']//div//li//div[2]//div[2] | //li[@class='list-group-item']//div//div[2]//div[1]";
        $approverElements = $page->findAll('xpath', $xpath);
        foreach ($approverElements as $element) {
            $approverList[] = $element->getText();
        }
        $expectedCount = count($approverRecords);
        $actualCount = count($approverList);
        if ($expectedCount === $actualCount) {
            for ($i = 0; $i < $expectedCount; $i++) {
                if ($approverRecords[$i] !== $approverList[$i]) {
                    throw new \Exception ("Order mismatched for element $I ('{$approverRecords[$i]}', '{$approverList[$i]}')");
                }
            }
        } else {
            throw new \Exception ("The expected elements count is $expectedCount but the actual count is $actualCount");
        }
    }

    /**
     * @Then /^I (\w+) "([^"]*)" below "([^"]*)"$/
    */
    public function iCheckBelow($option, $value, $label)
    {
        $page = $this->getSession()->getPage();
        $perLabel = $label;
        if (strpos($label, ">")) {
            $split = explode(">", $label);
            $revList = array_reverse($split);
            $label = $revList[0];
        }
        $xpath = "//div[@class='display' and text()='$value']/preceding-sibling::div/a[text()='" . trim($label) . "']/ancestor::li/div/input[@type='checkbox']";
        $xpath = $page->find('xpath', $xpath);
        if ($xpath) {
            if ($option === 'check') {
                $xpath->check();
            } else {
                $xpath->uncheck();
            }
        } else {
            throw new \Exception("Could not find $value below the $perLabel");
        }
    }

    /**
     * @Then /^I will (not )?see file type as "([^"]*)"$/
    */
    public function iWillSeeFileTypeAs($not, $document)
    {
        $page = $this->getSession()->getPage();
		$this->iWillNotSee("Converting");
        $xpath = $page->find('xpath', "//a[@title='" . $this->translate($document) . "' and (@class='list-action-icn icn-file_doc' or @class='list-action-icn icn-file_pdf')]");
        if (!$xpath xor $not) {
            throw new \Exception('File type ' . $document . ' was ' . ($not ? '' : 'not ') . 'found');
        }   
    }

    /**
     * @When I use :button button in :record
     * @When I use :button link in :record
     */
    public function iUseButtonIn($button, $record)
    {
        $translateButton = $this->translate($button); 
        $this->clickXpath("//*[contains(text(),'$record') or a[normalize-space()='$record']]/ancestor::tr/descendant::*[@title='$translateButton' or @alt='$translateButton' or @value='$translateButton' or text()='$translateButton']");
    }
}