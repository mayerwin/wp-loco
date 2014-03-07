<?php
/**
 * Object representing a theme, plugin or domain within core code.
 * Packages are identified uniquely by a type (e.g. "theme") and internal wordpress name, e.g. "loco-translate".
 */
class LocoPackage {
    
    /**
     * Internal identifier, could be name, or path, or anything in future
     * @var string
     */    
    private $handle;
    
    /**
     * Default text domain, e.g. "loco"
     * @var string
     */    
    private $domain;
    
    /**
     * Nice descriptive name, e.g. "Loco Translate"
     * @var string
     */    
    private $name;
    
    /**
     * Locales with available translations
     * @var array 
     */    
    private $locales = array();     
    
    /**
     * POT files, per domain
     * @var array
     */            
    private $pot = array();
    
    /**
     * PO files, per domain, per locale
     * @var array
     */    
    private $po = array();
    
    /**
     * Paths under which there may be source code in any of our domains
     * @var array
     */    
    private $src = array();
    
    /** 
     * Directories last modification times, used for cache invalidation
     * @var array
     */    
    private $dirs = array();
    
    /**
     * @var int
     */    
    private $mtime = 0;
    
    /**
     * number of PO or POT files present
     * @var int
     */    
    private $nfiles = 0;

    /**
     * Cached meta data 
     * @var array
     */    
    private $_meta;    

    /**
     * Construct package from name, root and domain
     */    
    protected function __construct( $name_or_path, $domain, $name ){
        $this->handle = $name_or_path;
        $this->domain = $domain;
        $this->name = $name or $this->name = $domain;
    }   
    
    /**
     * Get default system languages directory
     */    
    protected function _lang_dir(){
        return WP_LANG_DIR;
    }    
    
    
    /**
     * Get package type, defaults to 'core'
     */
    public function get_type(){
        return 'core';
    }    
    
    /**
     * Get identifying pair of arguments for fetching this object
     * @return array
     */
    public function get_query(){
        return array (
            'name' => $this->handle,
            'type' => $this->get_type(),
        );        
    }    
    
    
    /**
     * Get package handle used for retreiving theme or plugin via wordpress functions 
     */
    public function get_handle(){
        return $this->handle;
    }    
    
    
    /**
     * Get descriptive package name
     */
    public function get_name(){
        return $this->name;
    }
    
    
    /**
     * Get all text domains with PO or POT files.
     */    
    private function get_domains(){     
        return array_unique( array_merge( array_keys($this->pot), array_keys($this->po) ) );
    }
    
    
    /**
     * Get default text domain
     */
    public function get_domain(){
        if( ! $this->domain ){
            $this->domain = $this->handle;
        }
        if( $this->domain === $this->handle ){
            // if text domain defaulted and existing files disagree, try to correct primary domain
            $candidates = $this->get_domains();
            if( $candidates && ! in_array( $this->domain, $candidates, true ) ){
                $this->domain = $candidates[0];
            }
        }
        return $this->domain;
    }    

    
    /**
     * Get time most recent PO/POT file was updated
     */
    public function get_modified(){
        return $this->mtime;
    }    
    

    /**
     * Add PO or POT file and set modified state
     */
    private function add_file( $path ){
        if( filesize($path) ){
            $this->mtime = max( $this->mtime, filemtime($path) );
            $this->nfiles++;
            $this->add_dir( dirname($path) );
            return true;
        }
    }

    
    /**
     * Add directory and remember last modification time
     */
    private function add_dir( $path ){
        if( ! isset($this->dirs[$path]) ){
            $this->dirs[$path] = filemtime($path);
        }
    }
    
    
    /**
     * find additional plugin PO under WP_LANG_DIR
     */
    private function add_lang_dir( $langdir, $domain ){      
        $pattern = $langdir.'/'.$domain.'{-*.po,.pot}';
        $nfiles = $this->nfiles;
        $files = LocoAdmin::find_grouped( $pattern, GLOB_NOSORT|GLOB_BRACE ) and
        $this->add_po( $files );
        // add $langdir if files added
        if( $nfiles !== $this->nfiles ){
            $this->add_dir( $langdir );
        }
    }
     

    
    /**
     * Add multiple locations from found PO and POT files
     * @return LocoPackage
     */
    private function add_po( array $files, $domain = '' ){
        if( isset($files['pot']) && is_array($files['pot']) ){
            foreach( $files['pot'] as $path ){
                $domain or $domain = LocoAdmin::resolve_file_domain($path) or $domain = $this->get_domain();
                $this->add_file($path) and $this->pot[$domain] = $path;
            }
        }
        if( isset($files['po']) && is_array($files['po']) ){
            foreach( $files['po'] as $path ){
                $domain or $domain = LocoAdmin::resolve_file_domain($path) or $domain = $this->get_domain();
                $locale = LocoAdmin::resolve_file_locale($path);
                $code = $locale->get_code() or $code = 'xx_XX';
                $this->add_file($path) and $this->po[ $domain ][ $code ] = $path;
            }
        }
        return $this;
    }    
    
    
    
    /**
     * Add any MO files for which PO files are missing
     */ 
    private function add_mo( array $files, $domain = '' ){
        foreach( $files as $mo_path ){
            $domain or $domain = LocoAdmin::resolve_file_domain($mo_path) or $domain = $this->get_domain();
            $locale = LocoAdmin::resolve_file_locale($mo_path);
            $code = $locale->get_code() or $code = 'xx_XX';
            if( isset($this->po[$domain][$code]) ){
                // PO matched, ignore this MO
                // @todo better matching as PO may not be in same location as MO
                continue;
            }
            // add MO in place of PO
            $this->add_file($mo_path) and $this->po[$domain][$code] = $mo_path;
        }
    }    
    
    
    
    /**
     * Add a location under which there may be PHP source files for one or more of our domains
     * @return LocoPackage
     */        
    private function add_source( $path ){
        $this->src[] = $path;
        return $this;
    }    
    
    
    /**
     * Get most likely intended language folder
     */    
    public function lang_dir( $domain = '' ){
        $dirs = array();
        // check location of POT in domain
        foreach( $this->pot as $d => $path ){
            if( ! $domain || $d === $domain ){
                $path = dirname($path);
                if( is_writable($path) ){
                    return $path;
                }
                $dirs[] = $path;
            }
        }
        // check location of al PO files in domain
        foreach( $this->po as $d => $paths ){
            if( ! $domain || $d === $domain ){
                foreach( $paths as $path ){
                    $path = dirname($path);
                    if( is_writable($path) ){
                        return $path;
                    }
                    $dirs[] = $path;
                }
            }
        }
        // check languages subfolder of all source file locations
        foreach( $this->src as $path ){
            $pref = $path.'/languages';
            if( is_writable($pref) ){
                return $pref;
            }
            if( is_writable($path) ){
                return $path;
            }
            if( is_dir($pref) ){
                $dirs[] = $pref;
            }
            else {
                $dirs[] = $path;
            }
        }
        // check global languages location
        $path = $this->_lang_dir();
        if( is_writable($path) ){
            return $path;
        }
        $dirs[] = $path;
        // failed to get writable directory, so we'll just return the highest priority
        return array_shift( $dirs );
    }


    /**
     * Build name of PO file for given or default domain
     */
    public function create_po_path( LocoLocale $locale, $domain = '' ){
        if( ! $domain ){
            $domain = $this->get_domain();
        }
        $dir = $this->lang_dir( $domain );
        $name = $locale->get_code().'.po';
        // only prefix with text domain for plugins and files in global lang directory
        if( 'plugin' === $this->get_type() || 0 === strpos( $dir, $this->_lang_dir() ) ){
            $prefix = $domain.'-';
        }
        else {
            $prefix = '';
        }
        // if PO files exist, copy their naming format and use location if writable
        if( ! empty($this->po[$domain]) ){
            foreach( $this->po[$domain] as $code => $path ){
                $info = pathinfo( $path );
                $prefix = str_replace( $code.'.po', '', '', $info['basename'] );
                if( is_writable($info['dirname']) ){
                    $dir = $info['dirname'];
                    break;
                }
            }
        }
        return $dir.'/'.$prefix.$name;
    }
        
    
    /**
     * Get root of package
     */
    public function get_root(){
        foreach( $this->src as $path ){
            return $path;
        }
        return WP_LANG_DIR;        
    }   
    
    
    /**
     * Get all PO an POT files
     */
    public function get_gettext_files(){
        $files = array();
        foreach( $this->pot as $domain => $path ){
            $files[] = $path;
        }
        foreach( $this->po as $domain => $paths ){
            foreach( $paths as $path ){
                $files[] = $path;
            }
        }
        return $files;
    }
     
    
    /**
     * Check PO/POT paths are writable.
     * Called when generating root list view for simple error indicators.
     */    
    public function check_permissions(){
        $dirs = array();
        foreach( $this->pot as $path ){
            $dirs[ dirname($path) ] = 1;
            if( ! is_writable($path) ){
                throw new Exception( Loco::__('Some files not writable') );
            }
        }
        foreach( $this->po as $paths ){
            foreach( $paths as $path ){
                $dirs[ dirname($path) ] = 1;
                if( ! is_writable($path) ){
                    throw new Exception( Loco::__('Some files not writable') );
                }
                if( ! file_exists( preg_replace('/\.po$/', '.mo', $path) ) ){
                    throw new Exception( Loco::__('Some files missing') );
                }
            }
        }
        $dir = $this->lang_dir();
        if( ! is_writable($dir) ){
            throw new Exception( sprintf( Loco::__('"%s" folder not writable'), basename($dir) ) );
        }
        foreach( array_keys($dirs) as $path ){
            if( ! is_writable($path) ){
                throw new Exception( sprintf( Loco::__('"%s" folder not writable'), basename($dir) ) );
            }
        }
    }    
    
    
    /**
     * Get file permission for every important file path in package 
     */
    public function get_permission_errors(){
        $dirs = array();
        $paths = array();
        foreach( $this->pot as $path ){
            $dirs[ dirname($path) ] = 1;
            $paths[$path] = is_writable($path) ? '' : Loco::__('POT file not writable');
        }
        foreach( $this->po as $pos ){
            foreach( $pos as $path ){
                $dirs[ dirname($path) ] = 1;
                $paths[$path] = is_writable($path) ? '' : Loco::__('PO file not writable');
                $path = preg_replace('/\.po$/', '.mo', $path );
                $paths[$path] = file_exists($path) ? ( is_writeable($path) ? '' : Loco::__('MO file not writable') ) : Loco::__('MO file not found');
            }
        }
        if( ! isset($path) ){
            $base = $this->get_root();
            $dirs[ $base ] = 1;
            $dirs[ $base.'/languages' ] = 1;
        }
        $dirs[ $this->lang_dir() ] = 1;
        $dirs[ $this->_lang_dir() ] = 1;
        foreach( array_keys($dirs) as $dir ){
            $paths[$dir] = is_writable($dir) ? '' : Loco::__('Folder not writable');
        }
        ksort( $paths );
        return $paths;    
    }   
    
    
    /**
     * Fetch POT file for given, or default domain
     * @return string
     */    
    public function get_pot( $domain = '' ){
        if( ! $domain ){
            $domain = $this->get_domain();
        }
        if( ! empty($this->pot[$domain]) ){
            return $this->pot[$domain];
        }
        // no POT, but some authors may use locale-less PO files incorrectly as a template
        if( isset($this->po[$domain]) ){
            foreach( array('','xx_XX','en_US','en_GB') as $try ){
                if( isset($this->po[$domain][$try]) ){
                    $pot = $this->po[$domain][$try];
                    unset( $this->po[$domain][$try] );
                    return $this->pot[$domain] = $pot;
                }
            }
        }
        // no template candidate
        return '';
    }    
    
    
    /**
     * Fetch PO paths indexed by locale for given, or default domain
     * @return array
     */
    public function get_po( $domain = '' ){
        if( ! $domain ){
            $domain = $this->get_domain();
        }
        return isset($this->po[$domain]) ? $this->po[$domain] : array();
    }
    

    /**
     * Find all source files, currently only PHP
     */    
    public function get_source_files(){
        $found = array();
        foreach( $this->src as $dir ){
            foreach( LocoAdmin::find_php($dir) as $path ){
                $found[] = $path;
            }
        }
        return $found;
    }    
    
    
    /**
     * Get all source root directories
     */
    public function get_source_dirs( $relative_to = '' ){
        if( ! $relative_to ){
            return $this->src;
        }
        // calculate path from location of given file (which may not exist)
        if( pathinfo($relative_to,PATHINFO_EXTENSION) ){
            $relative_to = dirname($relative_to);
        }
        $dirs = array();
        foreach( $this->src as $target_dir ){
            $dirs[] = loco_relative_path( $relative_to, $target_dir );
        }
        return $dirs;
    }
    
    
    
    /**
     * Export meta data used by templates.
     * @return array
     */
    public function meta(){
        if( ! is_array($this->_meta) ){
            $pot = $po = array();
            // get POT files for all domains, fixing incorrect PO usage
            foreach( $this->get_domains() as $domain ){
                $path = $this->get_pot( $domain ) and
                $pot[] = compact('domain','path');
            }
            // get progress and locale for each PO file
            foreach( $this->po as $domain => $locales ){
                foreach( $locales as $code => $path ){
                    try {
                        unset($headers);    
                        $export = LocoAdmin::parse_po_with_headers( $path, $headers );
                        $po[] = array (
                            'path'   => $path,
                            'domain' => $domain,
                            'name'   => trim( str_replace( array('.po','.mo',$domain), array('','',''), basename($path) ), '-_'),
                            'stats'  => loco_po_stats( $export ),
                            'length' => count( $export ),
                            'locale' => loco_locale_resolve($code),
                        );
                    }
                    catch( Exception $Ex ){
                        continue;
                    }
                }
            }
            $this->_meta = compact('po','pot') + array(
                'name' => $this->get_name(),
                'root' => $this->get_root(),
                'domain' => $this->get_domain(),
            );
        }
        return $this->_meta;
    }    



    /**
     * Clear this package from the cache. Called to invalidate when something updates
     * @return LocoPackage
     */
    public function uncache(){
        $key = $this->get_type().'_'.$this->handle;
        Loco::uncache( $key );
        $this->_meta = null;
        return $this;
    }


    /**
     * Invalidate cache based on last modification of directories
     * @return bool whether cache should be invalidated
     */
    private function invalidate(){
        foreach( $this->dirs as $path => $mtime ){
            if( ! is_dir($path) || filemtime($path) !== $mtime ){
                return true;
            }
        }
    }


    /**
     * construct package object from theme
     * @return LocoPackage
     */
    private static function get_theme( $handle ){
        $theme = wp_get_theme( $handle );
        if( $theme && $theme->exists() ){
            $name = $theme->get('Name');
            $domain = $theme->get('TextDomain');
            $package = new LocoThemePackage( $handle, $domain, $name );
            $root = $theme->get_theme_root().'/'.$handle;
            $package->add_source( $root );
            // add PO and POT under theme root
            if( $files = LocoAdmin::find_po($root) ){
                $package->add_po( $files, $domain );
            }
            // pick up any MO files that have missing PO
            if( $files = LocoAdmin::find_mo($root) ){
                $package->add_mo( $files, $domain );
            }
            // find additional theme PO under WP_LANG_DIR/themes unless a child theme
            $package->add_lang_dir(  WP_LANG_DIR.'/themes', $domain );
            // child theme inherits parent template translations
            while( $parent = $theme->get_template() ){
                if( $parent === $handle ){
                    // circular reference
                    break;
                }
                $parent = LocoPackage::get( $parent, 'theme' );
                if( ! $parent ){
                    // parent missing
                    break;
                }
                // indicate that theme is a child
                $package->inherit( $parent );
                if( $domain && $domain !== $parent->domain ){
                    // child specifies its own domain and will have to call load_child_theme_textdomain
                }
                else if( ! empty($package->po) || ! empty($package->pot) ){
                    // child has its own language files and domain will be picked up when get_domain called
                    $package->get_domain();
                }
                else {
                    // else should child inherit parent domain?
                    $package->domain = $parent->get_domain();
                }
                break;
            }
            return $package;
        }
    }    
    
    
    /**
     * Construct package object from plugin array
     * note that handle is file path for plugins in Wordpress
     */
    private static function get_plugin( $handle ){
        $plugins = get_plugins();
        if( isset($plugins[$handle]) && is_array($plugins[$handle]) ){
            $plugin = $plugins[$handle];
            $domain = $plugin['TextDomain'] or $domain = str_replace('/','-',dirname($handle));
            $package = new LocoPluginPackage( $handle, $domain, $plugin['Name'] );
            $root = WP_PLUGIN_DIR.'/'.dirname($handle);
            $package->add_source( $root );
            // add PO and POT under plugin root
            if( $files = LocoAdmin::find_po($root) ){
                $package->add_po( $files, $domain );
            }
            // pick up any MO files that have missing PO
            if( $files = LocoAdmin::find_mo($root) ){
                $package->add_mo( $files, $domain );
            }
            // find additional plugin PO under WP_LANG_DIR/plugin
            $package->add_lang_dir(  WP_LANG_DIR.'/plugins', $domain );
            return $package;
        }
    }
    
    
    /**
     * construct a core package object from name
     */
    private static function get_core( $handle ){
        /*
        $files = LocoAdmin::pop_lang_dir($domain);
        if( $files['po'] || $files['pot'] ){
            $package = new LocoPackage( $domain, $handle );
            $package->add_po( $files );
            //
            Loco::cache( $key, $package );
            return $package;
        }
        */
    }
    
    
    
    /**
     * Get a package - from cache if possible
     * @param string unique name or identifier known to Wordpress
     * @param string "core", "theme" or "plugin"
     * @return LocoPackage
     */
    public static function get( $handle, $type ){
        $key = $type.'_'.$handle;
        $package = Loco::cached($key);
        if( $package instanceof LocoPackage ){
            if( $package->invalidate() ){
                $package = null;
            }
        }
        if( ! $package instanceof LocoPackage ){
            $getter = array( __CLASS__, 'get_'.$type );
            $package = call_user_func( $getter, $handle );
            if( $package ){
                $package->meta();
                Loco::cache( $key, $package );
            }
        }
        return $package;
    }    
    
    
    
    /**
     * @internal
     */
    private static function _sort_modified( LocoPackage $a, LocoPackage $b ){
        $a = $a->get_modified();
        $b = $b->get_modified();
        if( $a > $b ){
            return -1;
        }
        if( $b > $a ){
            return 1;
        }
        return 0;
    }      
    
    
    /**
     * Sort packages according to most recently updated language files
     */    
    public static function sort_modified( array $packages ){
        static $sorter = array( __CLASS__, '_sort_modified' );
        usort( $packages, $sorter );        
        return $packages;
    }    
    
    
    
}


/**
 * Extended package class for themes
 */
class LocoThemePackage extends LocoPackage {
    private $parent;
    protected function _lang_dir(){
        return WP_LANG_DIR.'/themes';
    }
    protected function inherit( LocoThemePackage $parent ){
        $this->parent = $parent->get_handle();
    }
    protected function is_child(){
        return ! empty($this->parent);
    }
    public function meta(){
        $meta = parent::meta();
        if( $this->parent ){
            $parent = LocoPackage::get( $this->parent, 'theme' );
            $pmeta = $parent->meta();
            $meta['parent'] = $parent->get_name();
            // merge parent resources unless child has its own domain
            if( $meta['domain'] === $pmeta['domain'] ){
                $meta['po'] = array_merge( $meta['po'], $pmeta['po'] );
                $meta['pot'] = array_merge( $meta['pot'], $pmeta['pot'] );
            }
        }
        return $meta;
    }
    public function get_type(){
        return 'theme';
    }      
}


/**
 * Extended package class for plugins
 */
class LocoPluginPackage extends LocoPackage {
    protected function _lang_dir(){
        return WP_LANG_DIR.'/plugins';
    }
    public function get_type(){
        return 'plugin';
    }      
}

