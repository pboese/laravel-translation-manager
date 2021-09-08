<?php namespace Barryvdh\TranslationManager;

use Illuminate\Http\Request;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Controller extends \App\Http\Controllers\Controller
{
    /** @var \Barryvdh\TranslationManager\Manager  */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->middleware('auth');
        parent::__construct();

        $this->manager = $manager;
    }

    public function getIndex($group = null)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $locales = $this->manager->getLocales();
        $groups = Translation::groupBy('group');
        $excludedGroups = $this->manager->getConfig('exclude_groups');
        if($excludedGroups){
            $groups->whereNotIn('group', $excludedGroups);
        }

        $groups = $groups->select('group')->orderBy('group')->get()->pluck('group', 'group');
        if ($groups instanceof Collection) {
            $groups = $groups->all();
        }
        $groups = [''=>'Choose a group'] + $groups;
        $numChanged = Translation::where('group', $group)->where('status', Translation::STATUS_CHANGED)->count();

        $allTranslationsByGroup = [];
        foreach($groups as $singleGroup) {
            $allTranslations = Translation::where('group', $singleGroup)->orderBy('key', 'asc')->get();
            $numTranslations = count($allTranslations);
            $allTranslationsByGroup[$singleGroup] = [
                'editUrl' => action('\Barryvdh\TranslationManager\Controller@postEdit', [$singleGroup]),
            ];
            foreach($allTranslations as $translation){
                $allTranslationsByGroup[$singleGroup]['translations'][$translation->key][$translation->locale] = $translation;
            }

        }

        $allTranslations = Translation::where('group', $group)->orderBy('key', 'asc')->get();
        $numTranslations = count($allTranslations);
        $translations = [];
        foreach($allTranslations as $translation){
            $translations[$translation->key][$translation->locale] = $translation;
        }

        return view('translation-manager::index')
            ->with('translations', $translations)
            ->with('locales', $locales)
            ->with('groups', $groups)
            ->with('allTranslationsByGroup', $allTranslationsByGroup)
            ->with('group', $group)
            ->with('numTranslations', $numTranslations)
            ->with('numChanged', $numChanged)
            ->with('editUrl', $group ? action('\Barryvdh\TranslationManager\Controller@postEdit', [$group]) : null)
            ->with('deleteEnabled', $this->manager->getConfig('delete_enabled'));
    }

    public function getView($group = null)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        return $this->getIndex($group);
    }

    protected function loadLocales()
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        //Set the default locale as the first one.
        $locales = Translation::groupBy('locale')
            ->select('locale')
            ->get()
            ->pluck('locale');

        if ($locales instanceof Collection) {
            $locales = $locales->all();
        }
        $locales = array_merge([config('app.locale')], $locales);
        return array_unique($locales);
    }

    public function postAdd($group = null)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $keys = explode("\n", request()->get('keys'));

        foreach($keys as $key){
            $key = trim($key);
            if($group && $key){
                $this->manager->missingKey('*', $group, $key);
            }
        }
        return redirect()->back();
    }

    public function postEdit($group = null)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        if(!in_array($group, $this->manager->getConfig('exclude_groups'))) {
            $name = request()->get('name');
            $value = request()->get('value');

            list($locale, $key) = explode('|', $name, 2);
            $translation = Translation::firstOrNew([
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
            ]);
            $translation->value = (string) $value ?: null;
            $translation->status = Translation::STATUS_CHANGED;
            $translation->save();
            return array('status' => 'ok');
        }
    }

    public function postDelete($group, $key)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        if(!in_array($group, $this->manager->getConfig('exclude_groups')) && $this->manager->getConfig('delete_enabled')) {
            Translation::where('group', $group)->where('key', $key)->delete();
            return ['status' => 'ok'];
        }
    }

    public function postImport(Request $request)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $replace = $request->get('replace', false);
        $counter = $this->manager->importTranslations($replace);

        return ['status' => 'ok', 'counter' => $counter];
    }

    public function postFind()
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $numFound = $this->manager->findTranslations();

        return ['status' => 'ok', 'counter' => (int) $numFound];
    }

    public function postPublish($group = null)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $json = false;

        if($group === '_json'){
            $json = true;
        }

        $this->manager->exportTranslations($group, $json);

        return ['status' => 'ok'];
    }

    public function postAddGroup(Request $request)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $group = str_replace(".", '', $request->input('new-group'));
        if ($group)
        {
            return redirect()->action('\Barryvdh\TranslationManager\Controller@getView',$group);
        }
        else
        {
            return redirect()->back();
        }
    }

    public function postAddLocale(Request $request)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $locales = $this->manager->getLocales();
        $newLocale = str_replace([], '-', trim($request->input('new-locale')));
        if (!$newLocale || in_array($newLocale, $locales)) {
            return redirect()->back();
        }
        $this->manager->addLocale($newLocale);
        return redirect()->back();
    }

    public function postRemoveLocale(Request $request)
    {
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        foreach ($request->input('remove-locale', []) as $locale => $val) {
            $this->manager->removeLocale($locale);
        }
        return redirect()->back();
    }

    public function postTranslateMissing(Request $request){
        if(!$this->current_user->hasRole('superadmin')) {
            abort(401);
        }

        $locales = $this->manager->getLocales();
        $newLocale = str_replace([], '-', trim($request->input('new-locale')));
        if($request->has('with-translations') && $request->has('base-locale') && in_array($request->input('base-locale'),$locales) && $request->has('file') && in_array($newLocale, $locales)){
            $base_locale = $request->get('base-locale');
            $group = $request->get('file');
            $base_strings = Translation::where('group', $group)->where('locale', $base_locale)->get();
            foreach ($base_strings as $base_string) {
                $base_query = Translation::where('group', $group)->where('locale', $newLocale)->where('key', $base_string->key);
                if ($base_query->exists() && $base_query->whereNotNull('value')->exists()) {
                    // Translation already exists. Skip
                    continue;
                }
                $translated_text = Str::apiTranslateWithAttributes($base_string->value, $newLocale, $base_locale);
                request()->replace([
                    'value' => $translated_text,
                    'name' => $newLocale . '|' . $base_string->key,
                ]);
                app()->call(
                    'Barryvdh\TranslationManager\Controller@postEdit',
                    [
                        'group' => $group
                    ]
                );
            }
            return redirect()->back();
        }
        return redirect()->back();
    }
}
