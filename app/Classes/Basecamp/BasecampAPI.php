<?php

namespace App\Classes\Basecamp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;


class BasecampAPI
{
    /**
     * API Access token
     * @var string
     */
    protected $accessToken = null;

    /**
     * Base API URL
     * @var string
     */
    protected $baseUri = null;

    /**
     * API Options
     * @var array
     */
    private $options = [];

    /**
     * @var Collection
     */
    private $restrictedEmails = null;

    /**
     * @var string
     */
    private $apiPrefix = 'api-';

    /**
     * @var Request
     */
    private $request = null;

    /**
     * Get API prefix
     * @return string
     */
    protected function prefix(){ return $this->apiPrefix; }

    /**
     * BasecampAPI constructor.
     * @param $accessToken string Access token to use
     */
    public function __construct()
    {
        $this->baseUri = config('services.basecamp.url');

        try {
            $this->setAccessToken(cache('BCaccessToken', null));
        } catch(QueryException $e){
            $this->setAccessToken('');
        }

        $this->options = [
            'cacheEnabled' => config('services.basecamp.cachingEnabled'),
            'cacheDecayTime' => config('services.basecamp.cacheAgeOff')
        ];

        $this->restrictedEmails = collect([]);
    }

    /**
     * Set basecamp URI
     * @param $uri string
     */
    public function setRootURI($uri)
    {
        $this->baseUri = $uri;
    }

    /**
     * @param $token string Basecamp Access token
     */
    public function setAccessToken($token){
        $this->accessToken = $token;
    }

    /**
     * Set request
     * @param Request $request
     */
    public function setRequest(Request $request){
        $this->request = $request;
    }

    /**
     * Is caching enabled?
     * @return bool
     */
    protected function cacheEnabled(){ return $this->options['cacheEnabled']; }

    /**
     * How long should the cache live for? (in minutes)
     * @return int
     */
    protected function cacheDecayTime(){ return $this->options['cacheDecayTime']; }

    /**
     * Set people to be filtered out of site
     * @param array $emails
     */
    public function setEmailFilters($emails = []){
        $this->restrictedEmails = collect($emails)->transform(function($email){
            return strtolower($email);
        });
    }

    /**
     * Should person be filtered?
     * @param $person object Basecamp Person Object
     * @return bool
     */
    protected function personShouldBeFiltered($person){
        return $this->restrictedEmails->search(strtolower($person->email_address)) !== false
            || in_array(strtolower($person->personable_type), ['tombstone', 'integration', 'dummyuser']);
    }

    /**
     * Get request to API
     * @param $resource string Resource URL
     * @param bool $allPages Get all pages in response
     * @param $force bool Force API call, ignore setting
     * @return object|array
     */
    public function get($resource, $allPages = true, $force = false){
        $client = new Client([
            'base_uri' => $this->baseUri
        ]);

        //Trim left slashes
        $resource = ltrim($resource,'/');

        $cacheName = $this->prefix().'call-'.$resource;

        //If caching enabled and not forced, return cached response
        if($this->cacheEnabled() && !$force && ($cached = cache($cacheName)))
            return json_decode($cached);

        $res = null;

        //Cache miss call to API
        try {
            $res = $client->request('GET', $resource, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'User-Agent' => env('BASECAMP_AGENT')
                ]
            ]);
        } catch(RequestException $e){
            abort($e->getResponse()->getStatusCode(), $e->getResponse()->getReasonPhrase());
        }

        //Get JSON payload
        $json = $res->getBody()->getContents();
        $decoded = json_decode($json);

        $matches = [];
        //More pages?
        if(count($res->getHeader('Link')) > 0 && preg_match('/<([^>]+)>/', $res->getHeader('Link')[0], $matches) > 0)
            $decoded = array_merge($decoded, $this->get($matches[1], $force));

        //Cache this if it's a 200 response
        if($this->cacheEnabled() && $res->getStatusCode() == 200)
            cache([$cacheName => json_encode($decoded)], $this->cacheDecayTime());

        return $decoded;
    }

    /**
     * Put Request to API
     * @param $resource string Resource URL
     * @param $data array Data to pass to api
     * @return object|array
     */
    public function put($resource, $data){
        $client = new Client([
            'base_uri' => $this->baseUri
        ]);

        //Trim left slashes
        $resource = ltrim($resource,'/');

        //Cache miss call to API
        $res = $client->request('PUT', $resource, [
            'json' => $data,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'User-Agent' => env('BASECAMP_AGENT')
            ]
        ]);

        //Get JSON payload
        $json = $res->getBody()->getContents();

        return json_decode($json);
    }

    /**
     * Invite person to basecamp
     * @param $project int Project ID
     * @param $people string|array People emails
     * @return object
     */
    public function invitePeople($project, $people){
        $invites = [];

        if(is_array($people))
            foreach($people as $email)
                $invites[] = [
                    'name' => explode('@', $email)[0],
                    'email_address' => $email
                ];
        else {
            $invites[] = [
                'name' => explode('@', $people)[0],
                'email_address' => $people
            ];
        }

        return $this->put('projects/'.$project.'/people/users.json', [
            'create' => $invites
        ]);
    }

    /**
     * Grant permission to user in project
     * @param $project int Project ID
     * @param $people int|array|object Person IDs to submit
     * @return object
     */
    public function projectGrant($project, $people)
    {
        if(is_object($people))
            $people = [$people->id];

        if(!is_array($people))
            $people = [$people];

        return $this->put('projects/'.$project.'/people/users.json', [
            'grant' => $people
        ]);
    }

    /**
     * Invite or grant access to project
     * @param $project int Project ID
     * @param $email string Email of user
     * @return object
     */
    public function inviteOrGrant($project, $email)
    {
        $existingPerson = null;
        foreach($this->people() as $person){
            if($person->email_address == auth()->user()->email) {
                $existingPerson = $person;
                break;
            }
        }

        if($existingPerson)
            return $this->projectGrant($project, $existingPerson);
        else
            return $this->invitePeople($project, $email);
    }

    /**
     * Get array of all projects
     * @param bool $stripTeamHash From project list, strip out all team hashing
     * @return Collection
     */
    public function projects($stripTeamHash = true){
        $cacheName = $this->prefix().'projects'.$stripTeamHash;

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return collect($cached);

        //Miss, call to API
        $resp = $this->get('projects.json');

        //Filter out anything that is not a 'project'
        $projects = collect($resp)->filter(function($project){
            return strtolower($project->purpose) == 'topic';
        })->transform(function($project) use ($stripTeamHash){
            unset($project->dock);

            if($stripTeamHash && ($str = $this->getTeamString($project->description)))
                $project->description = str_replace($str, '', $project->description);

            return $project;
        })->values();

        //Cache
        if($this->cacheEnabled())
            cache([$cacheName => $projects->jsonSerialize()], $this->cacheDecayTime());

        return $projects;
    }

    /**
     * Get every page in request (WARNING: DOES NOT CARE ABOUT RAM)
     * @param $url string url
     * @return object
     */
    private function getRecursive($url){
        $resp = $this->get($url);

        if(is_null($resp->url))
            return $resp;

        $resp->data = $this->getRecursive($resp->url);

        return $resp;
    }

    /**
     * Get basecamp project
     * @param $id int Project ID
     * @return object
     */
    public function project($id){
        $cacheName = $this->prefix().'project-'.$id;

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $project = $this->get('projects/'.$id.'.json');

        $project->people = $this->peopleInProject($project->id);

        foreach($project->dock as $key => $dock){
            if($dock->enabled) {
                $project->dock[$dock->name] = $dock;
            }

            unset($project->dock[$key]);
        }

        $project->dock = (object)$project->dock;

        if($set = &$project->dock->todoset)
            $set->data = $this->get($set->url);

        if($vault = &$project->dock->vault)
            $vault->data = $this->get($vault->url);

        if($board = &$project->dock->message_board)
            $board->data = $this->get($board->url);

        if($schedule = &$project->dock->schedule)
            $schedule->data = $this->get($schedule->url);

        //Departments involved
        $project->departments = null;

        if($str = $this->getTeamString($project->description)){
            $matches = [];

            preg_match_all('/([0-9]+)/', $str, $matches);
            $project->description = str_replace($str, '', $project->description);

            $project->departments = collect($matches)->splice(1)->last();
        }

        if($this->cacheEnabled())
            cache([$cacheName => json_encode($project)], $this->cacheDecayTime());

        return $project;
    }

    /**
     * Get project todos
     * @param $project object Project object
     * @return object
     */
    public function projectTodos($project)
    {
        $cacheName = $this->prefix().'project-'.$project->id.'-todos';

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $todolists = $this->get($project->dock->todoset->data->todolists_url);

        foreach($todolists as &$list){
            $list->todos = array_merge($this->get($list->todos_url), $this->get($list->todos_url.'?completed=true'));
        }

        if($this->cacheEnabled())
            cache([$cacheName => json_encode($todolists)], $this->cacheDecayTime());

        return $todolists;
    }

    /**
     * Get project todos
     * @param $project object Project object
     * @return object
     */
    public function projectEvents($project)
    {
        $cacheName = $this->prefix().'project-'.$project->id.'-events';

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $events = collect($this->get($project->dock->schedule->data->entries_url))
                        ->sortBy('starts_at')->values();

        if($this->cacheEnabled())
            cache([$cacheName => json_encode($events)], $this->cacheDecayTime());

        return $events;
    }

    /**
     * Get project todos
     * @param $project object Project object
     * @todo Handle older history
     * @return Collection
     */
    public function projectHistory($project)
    {
        $cacheName = $this->prefix().'project-'.$project->id.'-history';

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $buckets = [];
        foreach($project->dock as $dock)
            if(property_exists($dock, 'data'))
                $buckets[] = $dock->data->bucket->id;

        $types = ['Document', 'Todo', 'Todolist', 'Upload'];

        $history = collect([]);

        foreach($types as $type)
            $history[$type] = $this->get('projects/recordings.json?type='.$type
                .'&bucket='.implode(',', $buckets)
                .'&sort=created_at&direction=asc', false);

        $historicalList = $history->flatten(1)->values();

        if($this->cacheEnabled())
            cache([$cacheName => json_encode($historicalList)], $this->cacheDecayTime());

        return $historicalList;
    }

    /**
     * Get all people in project
     * @param $id int Project ID
     * @return Collection
     */
    public function peopleInProject($id){
        return collect($this->get('projects/'.$id.'/people.json'))->filter(function($person){
            return !$this->personShouldBeFiltered($person);
        })->values();
    }

    /**
     * Get all teams
     * @return Collection
     */
    public function teams(){
        $cacheName = $this->prefix().'teams';

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return collect($cached);

        //Filter anything not a 'team'
        $teams = collect($this->get('projects.json'))->filter(function($team){
            return strtolower($team->purpose) == 'team';
        })->transform(function($team) {
            unset($team->dock);
            return $team;
        })->values();

        //Cache
        if($this->cacheEnabled())
            cache([$cacheName => $teams->jsonSerialize()], $this->cacheDecayTime());
        return $teams;
    }

    /**
     * Get team by ID
     * @param $id int Team ID
     * @return object
     */
    public function team($id){
        $cacheName = $this->prefix().'team-'.$id;

        //Check cache
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $team = $this->project($id);

        //Add additional fields
        $team->projects = $this->teamProjects($team->id);
        $team->memberships = $this->peopleInProject($team->id);

        //Cache
        if($this->cacheEnabled())
            cache([$cacheName => json_encode($team)], $this->cacheDecayTime());

        return $team;
    }

    /**
     * Get team by name
     * @param $name string Team name
     * @return object
     */
    public function teamByName($name){
        $cacheName = $this->prefix().'team-'.$name;

        //Check cache for ID
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        //Go through all teams until it's found
        $team = $this->teams()->filter(function($team) use ($name){
            return $name == preg_replace('/&/', 'and', preg_replace('/\\s/', '-', strtolower($team->name)));
        });

        $team = $team->count() > 0 ? $team->first() : null;

        if(is_null($team))
            return null;

        //Add additional fields
        $team->projects = $this->teamProjects($team->id);
        $team->memberships = $this->peopleInProject($team->id);

        //Cache
        if($this->cacheEnabled())
            cache([$cacheName => json_encode($team)], $this->cacheDecayTime());

        return $team;
    }

    /**
     * Get team hash out of string, returns null if not found
     * @param $str string
     * @return null|string
     */
    private function getTeamString($str){
        $matches = [];
        if(preg_match_all('/#teams(?: (\d+))+/', $str, $matches) > 0)
            return $matches[0][0];

        return null;
    }

    /**
     * Get all team projects
     * @param $dept int Department ID
     * @return Collection
     */
    public function teamProjects($dept){
        $cacheName = $this->prefix().'team-projects-'.$dept;

        if($this->cacheEnabled() && $cached = cache($cacheName))
            return collect($cached);

        //Go through all projects until API is found
        $teamProjects = $this->projects(false)->filter(function($project) use ($dept){
            if(is_null($project->description)) return false;

            if($str = $this->getTeamString($project->description)) {
                $matches = [];

                preg_match_all('/([0-9]+)/', $str, $matches);
                $project->description = str_replace($str, '', $project->description);

                return in_array($dept, collect($matches)->splice(1)->last());
            }

            return false;
        })->values();

        //Cache
        if($this->cacheEnabled())
            cache([$cacheName => $teamProjects->jsonSerialize()], $this->cacheDecayTime());

        return $teamProjects;
    }


    /**
     * Get all people
     * @return Collection
     */
    public function people(){
        return collect($this->get('people.json'))->filter(function($person){
            return !$this->personShouldBeFiltered($person);
        })->values();
    }

    /**
     * Get person
     * @param $id int Person iD
     * @return object
     */
    public function person($id){
        $cacheName = $this->prefix().'person-'.$id;

        //Return cached person if thye exist
        if($this->cacheEnabled() && $cached = cache($cacheName))
            return json_decode($cached);

        $person = $this->get('people/'.$id.'.json');

        //Should they be filtered?
        if($this->personShouldBeFiltered($person))
            return null;

        //Get all projects
        $person->projects = $this->personProjects($person);

        //Get all departments they're in
        $person->departments = $this->personTeams($person);

        if($this->cacheEnabled())
            cache([$cacheName => json_encode($person)], $this->cacheDecayTime());

        return $person;
    }

    /**
     * Get all teams a person is in
     * @param $person
     * @return Collection
     */
    public function personTeams($person){
        return $this->teams()->filter(function($team) use ($person){
            foreach($this->peopleInProject($team->id) as $peep)
                if($peep->id == $person->id)
                    return true;

            return false;
        })->values();
    }

    /**
     * Get all projects a person has
     * @param $person object Person object
     * @return Collection
     */
    public function personProjects($person){
        return $this->projects()->filter(function($project) use ($person){
            foreach($this->peopleInProject($project->id) as $peep)
                if($peep->id == $person->id)
                    return true;

            return false;
        })->values();
    }

    /**
     * Get person by email
     * @param $email string Email
     * @return object|null
     */
    public function personByEmail($email){
        foreach($this->people() as $person)
            if($person->email_address == $email)
                return $person;

        return null;
    }
}