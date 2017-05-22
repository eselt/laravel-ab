<?php 

namespace ComoCode\LaravelAb\App;

use App\User;
use App\Visitor;
use ComoCode\LaravelAb\App\Events;
use ComoCode\LaravelAb\App\Experiments;
use ComoCode\LaravelAb\App\Goal;
use ComoCode\LaravelAb\App\Instance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class Ab 
{

    /**
     * @var static $session
     * Instance Object to identify user's session
     */
    protected static $session;

    /**
     * @var $instance
     *
     * Tracks every $experiment->fired condition the view is initiating
     * and event key->value pais for the instance
     */
    protected static $instance = [];

    /*
     * Individual Test Parameters
     */
    protected $name;
    protected $conditions = [];
    protected $fired;
    protected $goal;
    protected $metadata_callback;

    /**
     * @var Request
     */
    private $request;

    /**
     * Create a instance for a user session if there isnt once.
     * Load previous event -> fire pairings for this session if exist
     */
    public function __construct(Request $request)
    {
        // \Log::debug('Ab::__construct');

        $this->request = $request;
        $this->ensureUser(false);

    }

    public function ensureUser($forceSession = false)
    {
        if (! Session::has(config('laravel-ab.cache_key')) || $forceSession) {
            // Sprawdzamy czy Visitor ma podpiętą instancję testu
            // jeśli tak to bierzemy uid z instancji podpiętej do Visitora
            // w przeciwnym wypadku generujemy losowe uid
            if (User::current()->abTestInstance()) {
                $uid = User::current()->abTestInstance()->instance;
            } else {
                $uid = md5(uniqid().$this->request->getClientIp());
            }
            
            $laravel_ab_id = $this->request->cookie(config('laravel-ab.cache_key'), $uid);
            Session::put(config('laravel-ab.cache_key'),$uid);
        }

        if (empty(self::$session)) {
            // Sprawdzamy czy Visitor ma podpiętą instancję testu
            // jeśli tak to bierzemy uid z instancji podpiętej do Visitora
            // w przeciwnym wypadku bierzemy uid z sesji
            if (User::current()->abTestInstance()) {
                $uid = User::current()->abTestInstance()->instance;
            } else {
                $uid = Session::get(config('laravel-ab.cache_key'));
            }

            $instance = Instance::firstOrCreate([
                'instance' => $uid,
            ]);

            if ($instance->identifier != $this->request->getClientIp()) {
                $instance->identifier = $this->request->getClientIp();
                $instance->save();
            }

            self::$session = $instance;
        }
    }

    /**
     * @param array $session_variables
     * Load initial session variables to store or track
     * Such as variables you want to track being passed into the template.
     */
    public function setup(Array $session_varfiables = array())
    {
        foreach ($session_variables as $key=>$value) {
            $experiment = new self;
            $experiment->experiment($key);
            $experiment->fired = $value;
            $experiment->instanceEvent();
        }
    }

    /**
     *
     * When the view is rendered, this funciton saves all event->firing pairing to storage
     *
     */
    public static function saveSession() 
    {
        if (! empty(self::$instance)) {
            foreach (self::$instance as $instance) {
                $experiment = Experiments::firstOrCreate([
                    'experiment' => $instance->name,
                    'goal' => $instance->goal
                ]);

                $event = Events::firstOrCreate([
                    'instance_id' => self::$session->id,
                    'name' => $instance->name,
                    'value' => $instance->fired
                ]);

                $experiment->events()->save($event);
                self::$session->events()->save($event);
            }
        }

        return Session::get(config('laravel-ab.cache_key'));
    }

    /**
     * @param $experiment
     * @return $this
     *
     * Used to track the name of the experiment
     */
    public function experiment($experiment)
    {
        // \Log::debug('Ab::experiment: ' . $experiment);

        $this->name = $experiment;
        $this->instanceEvent();
        return $this;
    }

    /**
     * @param $goal
     * @return string
     *
     * Sets the tracking target for the experiment, and returns one of the conditional elements for display
     */
    public function track($goal) 
    {
        // \Log::debug('Ab::track: ' . $goal);

        $this->goal = $goal;

        ob_end_clean();

        $conditions = [];
        foreach ($this->conditions as $key=>$condition) {
            if (preg_match('/\[(\d+)\]/',$key,$matches)) {
                foreach (range(1,$matches[1]) as $index) {
                    $conditions[] = $key;
                }
            }
        }

        if (empty($conditions)) {
            $conditions = array_keys($this->conditions);
        }

        // has the user fired this particular experiment yet?
        if ($fired = $this->hasExperiment($this->name)) {
            $this->fired = $fired;
        }
        else {
            $this->setConditionToFire($conditions);
        }

        return $this->conditions[$this->fired];
    }

    protected function setConditionToFire($conditionKeys)
    {
        // Sprawdzamy czy Visitor jest otagowany jednym z tagów znajdująych się w $conditionKeys
        // Jeśli tak to ustawiamy klucz jako wylosowaną wartość
        // Tagi zapisujemy jako: "[nazwa_eksperymentu]nazwa_wylosowanego_wariantu"
        foreach ($conditionKeys as $key) {
            if (Visitor::isCurrentVisitorTaggedByKey('[' . $this->name . ']' . $key)) {
                $this->fired = $key;
                return;
            }
        }

        // W przeciwnym wypadku losujemy
        $this->fired = $this->randCondition($conditionKeys);
    }

    protected function randCondition($conditionKeys)
    {
        $experiment = Experiments::where([
            'experiment' => $this->name,
            'goal' => $this->goal,
        ])->first();

        if (null === $experiment) {
            return $this->_randCondition($conditionKeys);
        }

        $conditions = [];

        foreach ($experiment->events as $event) {
            if (isset($conditions[$event->value])) {
                $conditions[$event->value]++;
            } else {
                $conditions[$event->value] = 0;
            }
        }

        // Zapobiegamy błędom
        if (count($conditions) < 2) {
            return $this->_randCondition($conditionKeys);
        }

        asort($conditions);
        $keys = array_keys($conditions);
        $mostUsed = end($keys);
        $leastUsed = $keys[0];

        // Zapobiegamy błędom
        if ($conditions[$leastUsed] == $conditions[$mostUsed]) {
            return $this->_randCondition($conditionKeys);
        }

        // Jeśli odchylenie między najczęściej i najrzardziej losowaną opcją
        // jest większe niż 3 sztuk zwróć najrzardziej losowaną opcję
        $diff = $conditions[$mostUsed] - $conditions[$leastUsed];
        if ($diff > 3) {
            return $leastUsed;
        }

        return $this->_randCondition($conditionKeys);
    }

    protected function _randCondition($conditionKeys)
    {
        return $conditionKeys[rand(0, count($conditionKeys)-1)];
    }

    /**
     * @param $goal
     * @param goal $value
     *
     * Insert a simple goal tracker to know if user has reach a milestone
     */
    public function goal($goal, $value = null)
    {
        $goal = Goal::create(['goal'=>$goal, 'value'=>$value]);

        self::$session->goals()->save($goal);

        return $goal;
    }


    /**
     * @param $condition
     * @returns void
     *
     * Captures the HTML between AB condtions  and tracks them to their condition name.
     * One of these conditions will be randomized to some ratio for display and tracked
     */
    public function condition($condition)
    {
        $reference = $this;

        if (count($this->conditions) !== 0) {
            ob_end_clean();
        }

        $reference->saveCondition($condition, ''); // so above count fires after first pass

        ob_start(function($data) use ($condition, $reference) {
            $reference->saveCondition($condition, $data);
        });
    }

    /**
     * @param bool $forceSession
     * @return mixed
     *
     * Ensuring a user session string on any call for a key to be used.
     */
    public static function getSession()
    {
        return self::$session;
    }

    /**
     * @param $condition
     * @param $data
     * @returns void
     *
     * A setter for the condition key=>value pairing.
     */
    public function saveCondition($condition, $data)
    {
        $this->conditions[$condition] = $data;
    }

    /**
     * @param $experiment
     * @param $condition
     *
     * Tracks at an instance level which event was selected for the session
     */
    public function instanceEvent()
    {
        self::$instance[$this->name] = $this;
    }

    /**
     * @param $experiment
     * @return bool
     *
     * Determines if a user has a particular event already in this session
     */
    public function hasExperiment($experiment)
    {
        $session_events = self::$session->events()->get();
        foreach($session_events as $event){
            if ($event->name == $experiment){
                return $event->value;
            }
        }
        return false;
    }

    /**
     * Simple method for resetting the session variable for development purposes
     */
    public function forceReset()
    {
        $this->ensureUser(true);
    }

    public function toArray() 
    {
        return [$this->name => $this->fired];
    }

    public function getEvents()
    {
        return self::$instance;
    }
}