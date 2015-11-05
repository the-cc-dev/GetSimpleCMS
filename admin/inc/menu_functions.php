<?php if(!defined('IN_GS')){ die('you cannot load this page directly.'); }

/**
 * GetSimple Menu handlers and Hierarchy Functions
 * @package GetSimple
 * @subpackage menus_functions.php
 */

/*
 * **************************************************************************** 
 * MENU CORE FUNCTIONS
 * **************************************************************************** 
 *
 * glossary:
 * `MENU` An individual menu object typically multidimensional array with sub indexes for flat and nested configuration, internally stored in $SITEMENUS
 * `MENU FLAT` subarray is a parent hash array, with `children` index array and a `data` array
 * `MENU NEST` subarray is a heirachy nested parent child array, linked to `FLAT` via references which have to built from file load, as json does not store refs
 * `menuid` the menu id, used as slug to store filenames
 * `corepages menuid` is the internal CORE menu and is essentially the page index map
 * `corepages_menu menuid` is the internal CORE menu but caches for front end menus, as it contains only the menu items
 * `legacy menuid` is the flat 3.3.x version of the menu as it appeared in 3.3.x without multilevel menus, primarily ordered by menuorder
 */

// @todo these are not broken out yet
include_once(GSADMININCPATH.'menu_manage_functions.php'); // menu manipulation functions

define('GSMENULEGACY',false); // use legacy menus, single level flat menu

// menu ids
define('GSMENUIDCORE','corepages'); // default id for the core page menu cache
define('GSMENUIDCOREMENU','corepages_menu'); // default id for the core page DYNAMIC menu cache
define('GSMENUIDLEGACY','legacy'); // default id for the 3.3.x legacy flat menu cache

// menu array key indexs
define('GSMENUNESTINDEX','nested'); // menu array key for nested tree subarray
define('GSMENUFLATINDEX','flat'); // menu array key for flat parent hash tree subarray

// menu filter result enums
define('GSMENUFILTERSKIP',1);     // skip all children
define('GSMENUFILTERCONTINUE',2); // skip parent, continue with children inheriting closest parent
define('GSMENUFILTERSHIFT',3);    // skip parent, shift all children to root

// menu tree recursion callouts
// generic
define('GSMENUTREECALLOUT','menuCallout'); // menu tree callout
define('GSMENUTREEFILTERCALLOUT','menuCalloutFilter'); // menu tree filter callout
// navigation
define('GSMENUNAVCALLOUT','menuCallout'); // menu nav callout
define('GSMENUNAVFILTERCALLOUT','menuCalloutFilter'); // menu nav filter callout
// menu manager
define('GSMENUMGRCALLOUT','mmCallout'); // menu manager tree callout
define('GSMENUMGRFILTERCALLOUT','mmCalloutFilter'); // menu manager filter callout

// define('GSMENUFILTERDEBUG',true);

// which menu to use for default if legacy is disabled
define('GSMENUDEFAULT',GSMENUIDCOREMENU); 


/**
 * initialize upgrade, menu imports
 * @since  3.4
 * @return bool status of menu generation upgrade
 */
function initUpgradeMenus(){
    // import menu data from pages to flat legacy menu
    $menu   = importLegacyMenuFlat();
    $status = menuSave('legacy',$menu);
    debugLog(__FUNCTION__ . ": legacy menu save status " . ($status ? 'success' : 'fail'));

    // import ALL core pages to core nested tree
    $menu   = importLegacyMenuTree();
    $status &= menuSave(GSMENUIDCORE,$menu);
    debugLog(__FUNCTION__ . ": core menu save status " . ($status ? 'success' : 'fail'));

    $status &= saveCoreMenu();
    debugLog(__FUNCTION__ . ": core inmenu menu save status " . ($status ? 'success' : 'fail'));

    return $status;
}

/**
 * save core menu cache
 * get coremenu, remove all non menu items, including orphans
 * rebuild nest, save
 * @since  3.4
 * @param  bool $fixorphans if not true, orphans will be removed from the menu, else they will remain in place not sorted
 * @return success status
 */
function saveCoreMenu($fixorphans = false){
	$menu  = getMenuDataArray();
	$pages = filterKeyValueMatch(getPages(),'menuStatus','Y'); // get menu pages form pagecache
	$slugs = $pages;
	// filter non menu pages
	$menu[GSMENUFLATINDEX] = array_intersect_key($menu[GSMENUFLATINDEX],$slugs);

	// remove children attribs that are not in this menu from parent items
	foreach($menu[GSMENUFLATINDEX] as $item){
		$orphan = false;

		if(!empty($item['data']['parent']) && !isset($menu[GSMENUFLATINDEX][$item['data']['parent']])){
			$orphan = true;
		}

		if(isset($item['children'])){
			foreach($item['children'] as $key=>$child){
				if(!isset($menu[GSMENUFLATINDEX][$child])){
					unset($item['children'][$key]); // child is not a menu item remove it
				} else{
					if($orphan && $fixorphans !== true) unset($menu[GSMENUFLATINDEX][$child]); // child exists, but we are an ophan, remove child also
				}
			}
		}
		if($orphan && $fixorphans !== true) unset($menu[GSMENUFLATINDEX][$item['id']]);
	}
	$menu   = menuRebuildNestArray($menu);
    $status = menuSave(GSMENUIDCOREMENU,$menu);
    debugLog(__FUNCTION__ . ": core inmenu menu save status " . ($status ? 'success' : 'fail'));	
    return $status;
}

/**
 * imports menu from pages flat legacy style, menuOrder sorted only
 * @since  3.4
 * @return array new menu sorted by menuOrder
 */
function importLegacyMenuFlat(){
    $pages = getPagesSortedByMenu();
    $pages = filterKeyValueMatch($pages,'menuStatus','Y');
    $menu  = importMenuFromPages($pages,true);
    return $menu; 
}

/**
 * imports menu from pages nested tree style, parent, title, menuorder sorted
 * @since  3.4
 * @return array new menu nested tree sorted by hierarchy and menuOrder
 */
function importLegacyMenuTree($menu = false){
    $pages = getPagesSortedByMenuTitle();
    // @todo when menu filtered, does not retain pages with broken paths, problem for menus that should probably still show them
    if($menu) $pages = filterKeyValueMatch($pages,'menuStatus','Y');
    $menu  = importMenuFromPages($pages);
    return $menu;
}

/**
 * build a nested menu array from pages array parent/menuorder
 * create a parent hash table with references
 * builds nested array tree
 * recurses over tree and add depth, order, numchildren, (path and url)
 * @since  3.4
 * @param  array $pages pages collection to convert to menu
 * @param  bool  $flatten if true return menu as flat not nested ( legacy style )
 */
function importMenuFromPages($pages = null, $flatten = false){
    
    // flatten if importing from 3.3.x, does not generate tree, generates flat menu instead for legacy menuordering by menuOrder
    if($flatten) $parents = array(''=>$pages);
    else $parents = getParentsHashTable($pages, true , true); // get parent hash table of pages, useref, fixoprphans

    $tree    = buildTreewHash($parents,'',false,true,'url'); // get a nested array from hashtable, from root, no preserve, assoc array, use url key
    // debugLog($tree);
    $flatary = recurseUpgradeTree($tree); // recurse the tree and add stuff to $tree, returns flat array
    // debugLog($tree); // debug ref array
    // debugLog($flatary); // debug return array

    $nesttree[GSMENUNESTINDEX]  = &$tree; //add tree array to menu
    $nesttree[GSMENUFLATINDEX]  = &$flatary[GSMENUFLATINDEX]; // add flat array to menu
    // debugLog($nesttree); // debug full
    
    return $nesttree;
}

/**
 * builds a nested array from a parent hash table array
 *
 * input [parent]=>array(children) hash table
 * ['parentid'] = array('child1'=>(data),'child2'=>(data))
 *
 * output (nested)
 * ['parentid']['children']['child1'][data,childen]
 *
 * @since 3.4
 * @param  array   $elements    source array, parent hash table with or without child data
 * @param  string  $parentId    starting parent, root ''
 * @param  boolean $preserve    true, preserve all fields, else only id and children are kept
 * @param  boolean $assoc       return namedarray using $idKey for keys ( breaks dups )
 * @param  string  $idKey       key for id field
 * @param  string  $id          key for output id
 * @param  string  $childrenKey key for children sub array
 * @return array                new array
 */
function buildTreewHash($elements, $parentId = '', $preserve = false, $assoc = true, $idKey = 'id', $id = 'id',$childrenKey = 'children') {
    $branch = array();
    $args   = func_get_args();

    foreach ($elements[$parentId] as $element) {
        
        // if missing index field skip, bad record
        if(!isset($element[$idKey])) continue;
        
        $elementKey = $element[$idKey];
        // use index as keys else int
        $branchKey = $assoc ? $elementKey : count($branch);

        // if element is a parent recurse its children
        if (isset($elements[$elementKey])) {
            $args[1]  = $elementKey;
            // recurse elements children
            $children = call_user_func_array(__FUNCTION__,$args);

            // if element has children, add children to branches
            if ($children) {
                // if preserving all fields
                if($preserve){
                    $element[$childrenKey] = $children;
                    $branch[$branchKey] = $element;
                }
                else $branch[$branchKey] = array($id => $elementKey, $childrenKey => $children);
            }
        } 
        else{
            // else only add element
            if($preserve) $branch[$branchKey] = $element;
            else $branch[$branchKey] = array($id => $elementKey);
        }   
    }

    return $branch;
}

/**
 * recurse a nested parent=>child array and add heirachy information to it
 * 
 * modifies passed nested array by reference, returns flat array with references
 * 
 * handles non assoc arrays with id field
 * 
 * add adjacency info, depth, index, order nesting information
 * adds pathing info, parent, numchildren, and children subarray
 * 
 * reindexes as assoc array using 'id'
 * 
 * nested children subarray has same structure as roots, flat only contains slugs
 *
 * input array (&REF)
 *
 *  [0] = array(
 *    'id' => 'index',
 *    'children' => array()
 *  );
 * 
 * output array (&REF)
 * ['nest']
 *  ['index'] = array(
 *    'id' => 'index',
 *    'data' =>  &$flat['index']['data'],
 *    'children' => array(slug,slug)
 * 	)
 * )
 *
 * ['flat']
 *  ['index']  
 *    'data' => array(
 *        'url' => '/dev/getsimple/master/',
 *        'path' => 'inde10x',
 *        'depth' => 1,
 *        'index' => 9,
 *        'order' => 7
 *        'parent' => '',
 *        'numchildren' => 1
 *    )
 *  ),
 *
 * returns a flat array containing flat references
 * @todo this will have to be modified to allow it to retain flat data that does not change
 * @param  array  &$array    reference to array, so values can be refs
 * @param  str     $parent   parent for recursion
 * @param  integer $depth    depth for recursion
 * @param  integer $index    index ofr recursion
 * @param  array   &$indexAry indexarray result flat array
 * @return array             new array with added data
 */
function recurseUpgradeTree(&$array,$parent = null,$depth = 0,$index = 0,&$indexAry = array()){
    
    $thisfunc = __FUNCTION__;
    // debugLog($thisfunc . ' ' . count($array));

    // use temporary index to store currentpath
    if(!isset($indexAry['currpath'])) $indexAry['currpath'] = array();
    
    // init static $index primed from param
    if($index !== null){
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    }
    else static $index;

    $order = 0;
    $depth++;
    
    array_push($indexAry['currpath'],$parent);

    foreach($array as $key=>&$value){
        if(isset($value['id'])){
            $id = $value['id'];

            // hack to rekey array if not using id keys, needed for non assoc arrays, such as mm submit etc
            // this is not preffered but provided as a failsafe, reindex beforehand reindexMenuArray() to avoid modify ref in loop errors
            // skip rekeyed copies, we need a flag since array is reference, or else it will process the rekeyed elements twice
            if(isset($value['rekeyed'])){
            	unset($value['rekeyed']);
            	continue;
            }
            if($key !== $id){
                $array[$id] = $value; // this modifies &$array and may cause problems
				$array[$id]['rekeyed'] = true;
                unset($array[$key]); // remove old key
                $value = &$array[$id];
            }

            $order++;
            $index++;            

            $flatvalue = array();
            $flatvalue['id']                = $id; 
			$flatvalue['data']['parent']    = isset($parent) ? $parent : '';
			$flatvalue['data']['depth']     = $depth;
			$flatvalue['data']['index']     = $index;
			$flatvalue['data']['order']     = $order;
            $flatvalue['data']['dotpath']   = implode('.',$indexAry['currpath']).'.'.$id;

            recurseUpgradeTreeCallout($flatvalue,$id,$parent); // pass $value by ref for modification

            if(isset($value['children'])){
                $flatvalue['data']['numchildren'] = count($value['children']);
                $children = &$value['children'];
                $thisfunc($children,$id,$depth,null,$indexAry); // recursive call
            } else $flatvalue['data']['numchildren'] = 0;

            // add to flat array
            $indexAry[GSMENUFLATINDEX][$id] = $flatvalue;
            if(isset($value['children'])) $indexAry[GSMENUFLATINDEX][$id]['children'] = array_keys($value['children']);
            $value['data'] = &$flatvalue['data'];
        }
    }

    array_pop($indexAry['currpath']); // remove last path, closing out level
    if(!$indexAry['currpath']) unset($indexAry['currpath']); // if empty (exiting root level) remove it
    return $indexAry;
}

/**
 * callout for recurse, to add custom data
 * @since  3.4
 * @param  array &$value  ref array to manipulate
 * @param  string $id     $id of value
 * @param  string $parent parent of value
 * @return array          unused copy of array
 */
function recurseUpgradeTreeCallout(&$value,$id = '',$parent = ''){
    $value['data']['url']   = generate_url($id);
    $value['data']['path']  = generate_permalink($id,'%path%/%slug%');
    // debugLog($value);
    return $value; // non ref
}

/**
 * OUTPUT BUILDER FUNCTIONS
 */

/**
 * wrapper for getting menu tree
 * @since  3.4
 * @param  array   $parents array of parents
 * @param  bool    $wrap    generate outer wrap if true
 * @param  string  $callout inner element callout functionname
 * @param  str     $filter  filter callout functionname
 * @param  array   $args    arguments to pass to all callouts
 * @return str              output string
 */
function getMenuTree($menu, $wrap = false, $callback = 'treeCallout', $filter = null, $args = array()){
    $str = '';
    if($wrap) $str .= callIfCallable($callback,null,true);
    $str .= getMenuTreeRecurse($menu,$callback,$filter,0,0,$args);
    if($wrap) $str  .= callIfCallable($callback,null,true,false);
    return $str;
}

/**
 * RECURSIVE TREE ITERATOR NESTED ARRAY
 *
 * Does not generate main wrapper tags! 
 * tree output nested from children array
 * get tree from nested tree array with or without hierarchy info
 * passes menu `child` item to callouts, can be used on menuarrays where children are subarrays with 'id' and 'children' fields
 * 
 * supports adjacency info, but will calculate $level, $index, $order for you if it does not exist, useful when filtering stuff
 * 
 * 'children' subkey
 * 
 * array(
 *   'id' => 'parent',
 *   'children' => array(
 *      'id' => 'child1',
 *      'children' => array()
 *      'depth' => (optional)
 *    ),
 * )
 *
 * itemcallout($child,$level,$index,$order,$open);
 * 
 * filter callout accepts true to skip or GS definitions GSMENUFILTERSKIP, GSMENUFILTERCONTINUE, GSMENUFILTERSHIFT
 * filterresult = filtercallout($item)
 * 
 * @since  3.4
 * @param  array   $parents array of parents
 * @param  string  $callout   item callout functionname
 * @param  str     $filter  filter callout functionname
 * @param  integer $level   level for recursion incr
 * @param  integer $index   index for recursion incr, static reset if empty
 * @param  array   $args    arguments to pass to all callouts 
 * @return str              output string
 */
function getMenuTreeRecurse($parents, $callout= 'treeCallout', $filter = null, $level = 0, $index = 0, $args = array()){
    if(!$parents) return;
    $thisfunc = __FUNCTION__;

    // init static $index
    if($index !== null){
    	// primed from null param
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    } else static $index;

    // init order
    $order = 0;
    $str   = '';

    // detect if a page subarray was directly passed, auto negotiate children
    if(isset($parents['id']) && isset($parents['children'])) $parents = $parents['children'];

    // @todo: inline sorting test
    // test sorting, using sort index is the fastest to prevent resorting subarrays
    $sort = false;
    if($sort){
    	GLOBAL $sortkeys;
        // @todo since the recursive function only operates on parent subarray, we do not have access to menu itself, 
        // we could always sort by indices , and allow presorting the menu itself by sorting indices or $menu[GSMENUSORTINDEX]
    	// either way arraymergesort can do it
    	$parents = arrayMergeSort($parents,$sortkeys,false);
    	// debugLog($parents);
    	// debugLog(array_keys($parents));
    }

    foreach($parents as $key=>$child){

        if(!isset($child['id'])) continue;

        // do filtering
        $filterRes = callIfCallable($filter,$child,$level,$index,$order,$args); // get filter result
        if($filterRes){
            debugLog(__FUNCTION__ . ' filtered: (' . $filterRes . ') ' . $child['id'] . ' + ' . $child['data']['numchildren']);
            
            // filter skip, children skipped also, default
            if($filterRes === true || $filterRes === GSMENUFILTERSKIP){
                $str .= debugFilteredItem($child,$filterRes);
                continue;
            }

            // filter continue,  children inherit previous parent
            // @todo breaks $order
            if($filterRes === GSMENUFILTERCONTINUE) {

                $str .= debugFilteredItem($child,$filterRes);

                if(isset($child['children'])) {
                    $str.= $thisfunc($child['children'],$callout,$filter,$level,null,$args); # <li>....
                }
                continue;
            }

            // filter shift. children shifted to root, kludge, move skipped children to root via trick
            // move child to last sibling, then close depth level, shim depth afterwards with hidden elements
            if($filterRes === GSMENUFILTERSHIFT) {

                // no children just continue
                if(!isset($child['children'])) {
                	$str .= debugFilteredItem($child,$filterRes);                	
                    continue;
                }

                // if siblings exist, loop and perform recursion on all other siblings after this one being skipped
                // this is to alleviate the escaping
              	// @todo this breaks $order values, as they are reset on each call
              	// if we are already on level 1, we can probably skip this and switch to GSMENUFILTERCONTINUE instead
              	// and insert at root inline...
                if(count($parents) > 1){
                    $start = false; // use to init postiion of current sibling in parent array
                    foreach($parents as $keyb=>$childb){
                    	if(!isset($childb['id'])) continue;
                        if($childb['id'] == $child['id']){
                            $start = true; 
                            continue;
                        }
                        if(!$start) continue;
                        $str .= $thisfunc(array($childb),$callout,$filter,$level,null,$args);  # <li>...   
                    }
                }

                // close open depths
                for($i=0; $i<$level-1; $i++){
                    $str .= callIfCallable($callout,$child,false,false,$level,$index,$order,$args); # </li>
                    $str .= callIfCallable($callout,$child,true,false,$level,$index,$order,$args); # </ol>
                }

                $str .= debugFilteredItem($child,$filterRes);

                // output skipped children
                $str .= $thisfunc($child['children'],$callout,$filter,$level,null,$args);  # <li>...   

                // reopen open depths as hidden to clean up now extraneous lists
                // call callbacks with null child
                for($i=0; $i<$level-1; $i++){
                    $str .= callIfCallable($callout,null,false,true,$level,$index,$order,$args); # <li>
                    // $str .= '<li style="display:none">'; // sample
                    $str .= callIfCallable($callout,null,true,true,$level,$index,$order,$args); # <ol>                    
                    // $str .= '<ul style="display:none">'; // sample
                }
                return $str;
            }

        } // end filtering

        $index = $index+1;
        $level = $level+1;
        $order = $order+1;

        // call inner open
        $str .= callIfCallable($callout,$child,false,true,$level,$index,$order,$args); # <li>
        // has children 
        if(isset($child['children'])) {
            
            // recurse children
            $newstr = $thisfunc($child['children'],$callout,$filter,$level,null,$args);  # <li>...   
            
            // call outer open
            // only call outers if recurse return is not empty, in case all children were filtered, otherwise you get an empty outer
            if(!empty($newstr)){
            	$str .= callIfCallable($callout,$child,true,true,$level,$index,$order,$args); # <ol>
            	$str .= $newstr;
            	// call outer close
           		$str .= callIfCallable($callout,$child,true,false,$level,$index,$order,$args); # </ol>
        	}
        }
        
        // call inner close
        $str .= callIfCallable($callout,$child,false,false,$level,$index,$order,$args); # </li>
        $level--;
    }

    return $str;
}

/**
 * debug filtered items, by returning visible elements for them
 * @param  array $item      item processing
 * @param  int $filtertype GSMENUFILTER type
 * @return str             string to be inserted into menu
 */
function debugFilteredItem($item,$filtertype){
    if(!getDef('GSMENUFILTERDEBUG',true)) return '';
    $str = '<strong>#'.$item['index'].'</strong><div class="label label-error">removed</div> ' . $item['id']."<br/>";
    if(isset($item['children']) && $filtertype == GSMENUFILTERSKIP) $str .= '<br> ------ <div class="label label-error">removed</div><strong>'.$item['numchildren'].' children</strong>';
    return $str;
}

/**
 * RECURSIVE TREE ITERATOR NESTED ARRAY
 * minimal tree output from nested children array
 * assumes `id` and `children` subkey
 * passes menu child array to callouts, assumes everything you need is in the array, to be used with menu/ index/ or ref arrays
 * does not calculate heirachy data nor does it use it
 *
 * array(
 *   'id' => 'parent',
 *   'children' => array(
 *      'id' => 'child1',
 *      'children' => array()
 *      'depth' => 1
 *    ),
 * )
 * 
 * itemcallout($page,$open);
 * 
 * @param array   $parents parents array
 * @param str     $str     recursive str for append
 * @param string  $callout item callout functionname
 * @return str             output string
 */
function getMenuTreeMin($parents,$callout = 'treeCallout',$filter = null){
    if(!$parents) return;
    $thisfunc = __FUNCTION__;
    $str  = '';

    // if a page subarray was directly passed, auto negotiate children
    if(isset($parents['id']) && isset($parents['children'])) $parents = $parents['children'];

    foreach($parents as $key=>$child){
        if(!isset($child['id'])) continue;
        
        // call inner open
        $str .= callIfCallable($callout,$child,false); # <li>
        // has children 
        if(isset($child['children'])) {
            // call outer open
            $str .= callIfCallable($callout,$child,true); # <ol>
            // recurse
            $str .= $thisfunc($child['children'],$callout,$filter);  # <li>...   
            // call outer close
            $str .= callIfCallable($callout,$child,true,false); # </ol>
        }
        
        // call inner close
        $str .= callIfCallable($callout,$child,false,false); # </li>
    }
    
    return $str;
}

/**
 * generic tree inner callout function for recursive tree functions
 * outputs a basic list
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $outer is this a outer wrap node if true
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function treeCallout($item, $outer = false, $open = true, $level = '', $index = '', $order = ''){
    
    // outer wrap
    if($outer) return $open ? "\n<ul>" : "\n</ul>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : "</li>";
    
    // handle pages instead of menu items, pages do not have an id field
    if(!isset($item['id'])){
        if(!isset($item['url'])) return; // fail
        else $item['id'] = $item['url'];
    }

    $title =  $item['id'];
    // $title = debugTreeCallout(func_get_args());
    return $open ? "<li data-depth=".$level.'>'.$title : "</li>";
}

function treeCalloutFilter(){
	if(!getPage($item['id'])) return GSMENUFILTERCONTINUE;
}

/**
 * menu manager tree callout function
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $outer is this a outer wrap node if true 
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function mmCallout($item, $outer = false, $open = true,$level = '',$index = '' ,$order = '',$args = array()){

    // @note
    // hacking flat array conversion in, using args to pass menudata down, since recurse does not pass entire menu down to callouts.
    // The recursive tree will have to have the entire menu passed in, or the tree has to be ref linked to flat
    // either one means passing around the entire menu to recursion, or walking the entire tree to build the refs
    if(isset($args[0])) $item = getMenuItem($args[0],$item['id']);

    if($outer) return $open ? '<ol id="" class="dd-list">' : "</ol>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : '</li>';

    $page      = is_array($item) && isset($item['id']) ? getPage($item['id']) : getPage($item);
    $menuTitle = getPageMenuTitle($page['url']);
    // $pageTitle = '<strong>'.$page['title'].'.'.$level.'.'.$order.'</strong>';
    // $pageTitle = $pageTitle.'.'.$page['menuOrder'] .'.'.$page['menuStatus'];
    $pageTitle = $item['id'].'.'.$item['data']['index'] .'.'.$page['menuStatus'];
    // _debugLog($page['url'],$page['menuStatus']);
    if(empty($menuTitle)) $menuTitle = $item['id'];
    $pageTitle = truncate($pageTitle,30);
    $class     = $page['menuStatus'] === 'Y' ? ' menu' : ' nomenu';

    $str = $open ? '<li class="dd-item clearfix" data-id="'.$page['url'].'">'."\n".'<div class="dd-itemwrap '.$class.'"><div class="dd-handle"> '.$menuTitle.'<div class="itemtitle"><em>'.$pageTitle."</em></div></div></div>\n" : "</li>\n";
    return $str;
}


/**
 * tree filter filters pages not exist
 * @param  array $item menu child
 * @return mixed       filter result
 */
function  mmCalloutFilter($item){
	// if(!getPage($item['id'])) return GSMENUFILTERCONTINUE;
}

/**
 * menu inner item callout
 */
function menuCallout($item, $outer = false, $open = true, $level = '', $index = '', $order = '',$args = array()){
    
    if($outer) return $open ? "\n<ul>" : "\n</ul>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : '</li>';	
	if(!$open) return '</li>';
	
	$page        = getPage($item['id']);
	$classPrefix = $currentpage = '';
	extract($args); // extract arguments into cope
	
	$classes = $menu = '';
	$class = array();

	if(!empty($item['parent'])) $class['parent'] = $item['parent'];
	$class['url']    = $item['id'];
	$classes         = trim($classPrefix.implode(' '.$classPrefix,$class));
	// class="prefix.parent prefix.slug current active"
	if ($currentpage == $page['url']) $classes .= " current active";
	$title = getPageMenuTitle($item['id']); // @todo check in menu for title then fallback to this
	$menu .= '<li class="'. $classes .'"><a href="'. find_url($page['url'],$page['parent']) . '" title="'. encode_quotes(cl($title)) .'">'.var_out(strip_decode($title)).'</a>'."\n";
	
	return $menu;
}

/**
 * menu filter 
 * filters pages not exist, not in menu, and handles maxdepth limiter
 * @param  array $item menu child
 * @return mixed       filter result
 */
function menuCalloutFilter($item,$level,$index,$order,$args){
	$skip = GSMENUFILTERSKIP;
	// page not exist
	if(!getPage($item['id'])) return $skip;
	// page not in menu
    if(getPageFieldValue($item['id'],'menuStatus') !== 'Y') return $skip;
    // max depth limiter
    // _debugLog(func_get_args());
    if(isset($args['maxdepth']) && ($level > ($args['maxdepth']))) return $skip;
}

/**
 * HELPERS
 */

/**
 * reindex a nested menu array recursively
 * array[0] => array('id' => 'index')
 * array['index'] => array('id' => 'index'), and same for all children indexes
 * @since  3.4
 * @param  array $menu menu array
 * @return array       array reindexed
 */
function reindexMenuArray($menu, $force = false){
	$thisfunc = __FUNCTION__;
	foreach($menu as $key=>$item){
        if(!isset($item['id'])) continue;
		$id = $item['id'];
		if($id !== $key || $force){
			$menu[$id]  = $item;
			$menu[$key] = null;
			unset($menu[$key]);
		}
		if(isset($menu[$id]['children'])) $menu[$id]['children'] = $thisfunc($menu[$id]['children']);
	}

	return $menu;
}

/**
 * MENU IO FUNCTIONS
 */


/**
 * save basic json menu
 * convert basic menu string to gs menu array
 * @since  3.4
 * @param  str $jsonmenu json string of menu data
 * @return array gs menu data array
 */
function newMenuSave($menuid,$menu){
    $menu     = json_decode($menu,true);   // convert to array
	$menu     = reindexMenuArray($menu);   // add id as keys
    $menudata = recurseUpgradeTree($menu); // build full menu data
    $menudata[GSMENUNESTINDEX] = $menu;
    // _debugLog(__FUNCTION__,$menu);
    // _debugLog(__FUNCTION__,$menudata);
    if(getDef('GSMENULEGACY',true) && $menuid === GSMENUIDCORE) exportMenuToPages($menudata); // legacy page support
    return menuSave($menuid,$menudata);
}

/**
 * save menu file
 * @since  3.4
 * @param  str $menuid menu id
 * @param  array $data array of menu data
 * @return bool        success
 */
function menuSave($menuid,$data){
	GLOBAL $SITEMENU;
	$SITEMENU[$menuid] = $data;

    // remove GSMENUFLATINDEX if it exists ( cannot save refs in json )
    if(!$data || !$data[GSMENUNESTINDEX]){
        debugLog('menusave: menu is empty - ' .$menuid);
        return false;
    }

    $menufileext = '.json';
    $status = JSONsave($data,GSDATAMENUPATH.'menu_'.$menuid.$menufileext);
    return $status;
}

/**
 * get menu data from file resolved menu id
 * @since  3.4
 * @param  str $menuid menu id
 * @return str         raw file data
 */
function menuReadFile($menuid){
    $menufileext = '.json';
    $menustr = read_file(GSDATAMENUPATH.'menu_'.$menuid.$menufileext);
    return $menustr;
}

/**
 * read menu file
 * rebuild flat reference array
 * @since  3.4
 * @param  str $menuid menu id
 * @return array menudata
 * @return array menudata
 */
function menuRead($menuid){

	$menu = menuReadFile($menuid);

    if(!$menu){
        debugLog('menuRead: failed to load menu - ' . $menuid);
        return;
    }

    $menu = json_decode($menu,true);
    if(!isset($menu[GSMENUNESTINDEX])) menuRebuildNestArray($menu);
    return $menu;
}

/**
 * get available menu files, strips core menus
 * @since  3.4
 * @return array array of menuids available
 */
function getMenus(){
    $files = getFiles(GSDATAMENUPATH,'json');
    $coreMenus = array('menu_'.GSMENUIDCORE,'menu_'.GSMENUIDCOREMENU,'menu_'.GSMENUIDLEGACY);
    $menus = array();

    // remove core files
    foreach($files as $key=>$menufile){
        $menuid = getFileName($menufile);
        if(in_array($menuid,$coreMenus)) continue;
        $menus[] = $menuid;
    }

    debugLog($menus);
    return $menus;
}

/**
 * GETTERS
 */

/**
 * get a menu object from global or file
 * lazyload into SITEMENU global
 * 
 * @since  3.4
 * @uses  $SITEMENU
 * @param string $menuid menuid to retreive
 * @param bool $reload force reading from file else use global
 * @return array         menu array
 */
function getMenuDataArray($menuid = null){
	if(!$menuid) $menuid = GSMENUIDCORE;
    GLOBAL $SITEMENU;

    // return cached local
    if(isset($SITEMENU[$menuid])){
    	if(!$SITEMENU[$menuid]) debugLog("MENU IS EMPTY");
    	return $SITEMENU[$menuid];
    }	

    // load from file
    $menu = menuRead($menuid);
    if($menu) $SITEMENU[$menuid] = $menu;
    return $SITEMENU[$menuid];
}

/**
 * get menu data nested array
 * 
 * @since 3.4
 * @param  string $page   slug of page
 * @param  string $menuid menu id to fetch
 * @return array  menu sub array of page
 */
function getMenuDataNested($menuid = null){
	if(!$menuid) $menuid = GSMENUIDCORE;
    $menu = getMenuDataArray($menuid);
    if(!isset($menu)) return;
    if(!isset($menu[GSMENUNESTINDEX])) menuRebuildNestArray($menu);
    if(isset($menu[GSMENUNESTINDEX])) return $menu[GSMENUNESTINDEX];
}

/**
 * get menu data flat array
 * 
 * @since 3.4
 * @param  string $page   slug of page
 * @param  string $menuid menu id to fetch
 * @return array  menu sub array of page
 */
function getMenuDataFlat($menuid = null){
	if(!$menuid) $menuid = GSMENUIDCORE;	
    $menu = getMenuDataArray($menuid);
    if(!isset($menu)) return;
    if(isset($menu[GSMENUFLATINDEX])) return $menu[GSMENUFLATINDEX];
}

/**
 * get menu data as nested sub array
 * 
 * with or without parent item included
 * uses menu flat reference to nested array to resolve
 * @since  3.4
 * @param  string $page   slug of page
 * @param  bool   $parent include parent in sub menu if true
 * @param  string $menuid menu id to fetch
 * @return array  menu sub array of page
 */
function getMenuTreeData($page = '', $parent = false, $menuid = null){
	if(!$menuid) $menuid = GSMENUIDCORE;
   
    if(empty($page)) return getMenuDataNested($menuid); // no page, return full nest
    else $menudata = getMenuDataArray($menuid);
   
    if(!isset($menudata)) return;

    $item = getMenuItem($menudata,$page);

    if($item){
    	// if include parent
	    if($parent) {
	    	// if a child get parent and navigate from there
	    	// else we can grab directly from root
	    	$parenttree = getMenuItemParent($menudata,$page);
	    	if($parenttree){
	    		$parent = resolve_tree($menudata[GSMENUNESTINDEX], $parenttree['data']['dotpath']);
	    		$tree = array($page => $parent[$page]);
	    	}
	    	else $tree = array($page => $menudata[GSMENUNESTINDEX][$page]);
	    }
    	else $tree = resolve_tree($menudata[GSMENUNESTINDEX], $item['data']['dotpath']);

    	return $tree;
    }
    debugLog(__FUNCTION__ .': slug not found in menu('.$menuid.') - ' . $page);
}

/**
 * recursivly resolves a tree path to nested array sub array
 * 
 * $tree = array('path'=>array('to'=> array('branch'=>item)))
 * $path = array('path','to','branch')
 * @since 3.4
 * @param  array $tree array reference to tree
 * @param  array $path  array of path to desired branch/leaf
 * @param  string $childkey subkey if children are in subarrays
 * @return array        subarray from tree matching path
 */
function &resolve_tree($tree, $path, $childkey = 'children') {
	$thisfunc = __FUNCTION__;
	
	// explode dotpath, if not an array
	if(is_string($path)) $path = explode('.',trim($path,'.'));
    
    // return full tree if no path
    if(empty($path)) return $tree; 

    $empty = null; // placeholder for ref null

    if(!empty($tree) && isset($tree)){
    	
    	// using children subarray
    	if(isset($childkey)){
    		if(isset($tree[$path[0]][$childkey])){
    			return $thisfunc($tree[$path[0]][$childkey], array_slice($path, 1), $childkey); // recurse
    		}
    		else return $empty; // childkey not found, return null
    	}

    	return $thisfunc($tree[$path[0]], array_slice($path, 1), $childkey); // recurse
    }
    return $tree;
}

/**
 * EXPORT / LEGACY
 */


/**
 * save menu data to page files, refresh page cache
 * @since  3.4
 * @uses  saveMenuDataToPage
 * @param  array $menu menu array
 */
function exportMenuToPages($menu){
    $pages = getPages();
    if(!$menu){ 
        debugLog('no menu to save');
        return;
    }
    foreach($pages as $page){
        $id = $page['url'];
        // if not in menu wipe page menu data
        if(!isset($menu[GSMENUFLATINDEX][$id])){
            saveMenuDataToPage($id);
            continue;
        }
        // debugLog($menu[GSMENUFLATINDEX][$id]);
        $parent = $menu[GSMENUFLATINDEX][$id]['data']['parent'];
        
        // @todo: setting order will cause excessive updating of all files after an edited one
        // nor is order actually needed with multidimensional menus anymore
        // if this causes issues, it could be added back in for legacy purposes only
        // index is probably better to use also since legacy menus do not do a menu title sort, it is strictly menuOrder
        
        // debugLog($menu[GSMENUFLATINDEX][$id]);
        // $order  = $menu[GSMENUFLATINDEX][$id]['data']['index'];
        // saveMenuDataToPage($id,$parent,$order);
        saveMenuDataToPage($id,$parent);
    }

    // regen page cache
    create_pagesxml('true');
}

/**
 * set page data menu information
 * update page with parent and order, only if it differs
 * @since  3.4
 * @param  str $pageid page id to save
 * @param  str $parent page parent
 * @param  int $order page order
 * @return  bool success
 */
function saveMenuDataToPage($pageid,$parent = '',$order = ''){
    // skip updates if nothing changed
    if((string)returnPageField($pageid,'parent') == (string)$parent && (int)returnPageField($pageid,'menuOrder') == (int)$order) return;

    // set new parent and order
    $file = getPageFilename($pageid);
    if (file_exists($file)) {
        $data = getPageXML($pageid);
        $data->parent->updateCData($parent);
        if(isset($order)) $data->menuOrder->updateCData($order);
        return savePageXml($data,false);
    }
}


/**
 * GARBAGE
 * @todo : cleanup
 */

/**
 * 3.3.x menu save directly to pages
 */
function MenuOrderSave_LEGACY(){
    $menuOrder = explode(',',$_POST['menuOrder']);
    $priority = 0;
    foreach ($menuOrder as $slug) {
        $file = GSDATAPAGESPATH . $slug . '.xml';
        if (file_exists($file)) {
            $data = getPageXML($slug);
            if ($priority != (int) $data->menuOrder) {
                unset($data->menuOrder);
                $data->addChild('menuOrder')->addCData($priority);
                XMLsave($data,$file);
            }
        }
        $priority++;
    }
    create_pagesxml('true');
    $success = i18n_r('MENU_MANAGER_SUCCESS');
    return $success;
}

// debugging
function debugTreeCallout($args){
    $item = $args[0];
    // use internal
    // if(is_array($args) && !isset($item['data'])){
    //     $item['data']['depth'] = $args[2];
    //     $item['data']['index'] = $args[3];
    //     $item['data']['order'] = $args[4];
    //     // $debug .= ' [' . $args[2] . ']';
    // }
    $debug = '<strong>#'.(isset($args[3]) ? $args[3] :$item['index']).'</strong> '.$item['id'];
    $debug .= ' [ ' . $item['index'].' - '.$item['depth'].'.'.$item['order'] . ' ]';
    $debug .= ' [ ' . $args[3].' - '.$args[2].'.'.$args[4]. ' ]';
    return $debug;
}

/**
 * RECURSIVE TREE ITERATOR PARENT HASH TABLE
 * tree output from parent hashtable array
 * get tree from parent->child parenthashtable, where child is a pagesArray ref or copy of pages, heirachy info is ignored
 * passes child array to callouts, can be used on native parenthash arrays like parenthashtable, 
 * where children are values of page references or array, keys are parents
 * 
 * generates $level, $index, $order for you
 * 
 * array(
 *     'parent' => array(
 *         &$pagesArray['url'=>'child1'],
 *      ),
 * )
 *
 * itemcallout($id,$level,$index,$order,$open)
 *
 * @note CANNOT SHOW PARENT NODE IN SUBMENU TREE $key, since we can only find children not parents
 * @note not particulary used, but saving in case
 * @param  array   $parents array of parents
 * @param  string  $key     starting parent key
 * @param  string  $str     str for recursion append
 * @param  integer $level   level for recursion incr
 * @param  integer $index   index for recursion incr
 * @param  string  $callout   inner element callout functionname
 * @param  str     $filter  filter callout functionname
 * @return str              output string
 */
function getTreeFromParentHashTable($parents,$key = '',$level = 0,$index = 0, $callout = 'treeCalloutInner', $filter = null){
    if(!$parents) return;
    $thisfunc = __FUNCTION__;

    // init static $index primed from param
    if($index !== null){
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    }
    else static $index;

    $order = 0;
    $str   = '';
    $str  .= callIfCallable($callout,null,true,true,$level,$index,$order);

    foreach($parents[$key] as $parent=>$child){
        if(!is_array($child)) continue;
        if(callIfCallable($filter) === true) continue;

        $level = $level+1;
        $index = $index+1;
        $order = $order+1;

        $str .= callIfCallable($callout,$child,false,true,$level,$index,$order);

        if(isset($parents[$parent])) {
            $str.= $thisfunc($parents,$parent,$level,null,$callout,$filter);
        }
        $level--;
        $str .= callIfCallable($callout,$child,false,false,$level,$index,$order);
    }
    $str .= callIfCallable($callout,null,true,false,$level,$index,$order);
    return $str;
}


function legacyMenuManagerOutput($pages){

	if (count($pages) != 0) {
		echo '<form method="post" action="menu-manager.php">';
		echo '<ul id="menu-order" >';
		foreach ($pages as $page) {
			$sel = '';
			if ($page['menuStatus'] != '') {

				if ($page['menuOrder'] == '') {
					$page['menuOrder'] = "N/A";
				}
				if ($page['menu'] == '') {
					$page['menu'] = $page['title'];
				}
				echo '<li class="clearfix" rel="' . $page['slug'] . '">
												<strong>#' . $page['menuOrder'] . '</strong>&nbsp;&nbsp;
												' . $page['menu'] . ' <em>' . $page['title'] . '</em>
											</li>';
			}
		}
		echo '</ul>';
		echo '<div id="submit_line"><span>';
		echo '<input type="hidden" name="menuOrder" value=""><input class="submit" type="submit" value="' . i18n_r("SAVE_MENU_ORDER") . '" />';
		echo '</span></div>';
		echo '</form>';
	} else {
		echo '<p>'.i18n_r('NO_MENU_PAGES').'.</p>';
	}
}
/* ?> */