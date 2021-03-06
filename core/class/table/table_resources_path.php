<?php
if(!defined('IN_DZZ')) {
    exit('Access Denied');
}
class table_resources_path extends dzz_table
{
    public function __construct()
    {

        $this->_table = 'resources_path';
        parent::__construct();
    }
    /*public function fetch_fid_by_path($path){
        $path = trim($path);
        if($res = DB::fetch_first("select fid from %t where path = %s",array($this->_table,$path))){
            return $res['fid'];
        }
        return false;
    }*/
    /*
     * 返回文件夹路径
     * $pfid=>文件夹id
     * $pathkey=>为ture查询pathkey,兼容之前数据(临时处理)
     * */
    public function fetch_pathby_pfid($pfid,$pathkey = false){
        $pfid = intval($pfid);

        $fileds = ($pathkey) ? 'path,pathkey':'path';

        if($data = DB::fetch_first("select {$fileds} from %t where fid = %d",array($this->_table,$pfid))){
            return (!$pathkey) ? $data['path']:$data;
        }
        return false;
    }
    public function update_by_fid($fid,$setarr){
        return DB::update($this->_table,$setarr,array('fid'=>$fid));
    }
    public function fetch_fid_bypath($path){
        if($data = DB::fetch_first("select p.fid from %t p left join %t f on f.fid = p.fid  where p.path = %s and f.isdelete < %d",array($this->_table,'folder',$path,1))){
            return $data['fid'];
        }
        return false;
    }
    //获取路径文件名和文件所在文件夹fid
    public function get_filename_patharr($path){
        $patharr = array();
        $dir = dirname($path).'/';
        if(!$pfid  =  C::t('resources_path')->fetch_fid_bypath($dir)){
            return $patharr;
        }
        $filename = preg_replace('/^.+[\\\\\\/]/', '', $path);
        //如果是文件夹
        if(!$filename){
            $patharr = preg_split('/[\\\\\\/]/',$path);
            $patharr = array_filter($patharr);
            $filename = end($patharr);
        }
        $patharr['filename'] = $filename;
        $patharr['pfid'] = $pfid;
        return $patharr;
    }
    public function delete_by_path($path){
        $path = self::path_transferred_meaning($path);
        $fids = array();
        foreach(DB::fetch_all("select fid from %t where path regexp %s",array($this->_table,'^'.$path.'.*')) as $v){
            $fids[] = $v['fid'];
        }
        if(self::delete_by_fid($fids)){
            return true;
        }
        return false;
    }
    public function delete_by_fid($fid){
        if(!is_array($fid)) $fids = array($fid);
        else $fids = $fid;
        if(DB::delete($this->_table,"fid in (".dimplode($fids).")")){
            return true;
        }
        return false;
    }
    public function delete_by_pathkey($pathkey){
        return DB::delete($this->_table,"pathkey = ".$pathkey);
    }
    //通过pathkey获取文件夹下级及自身fid
    public function fetch_folder_containfid_by_pfid($pfid){
        $pfids = array($pfid);
        $path = $this->fetch_pathby_pfid($pfid,true);
        $pathkey = $path['pathkey'];
        $results = DB::fetch_all("select * from %t where pathkey regexp %s",array($this->_table,'^'.$pathkey.'.+'));
        foreach($results as $v){
            $pfids[] = $v['fid'];
        }
        return $pfids;
    }
    public function fetch_by_path($path,$prefix=''){
        $opath = trim($path);
        $patharr = explode('/',$opath);
        $path = self::path_transferred_meaning($path);
        $uid = getglobal('uid');
        if($prefix){
            switch ($prefix){
                case  'g':
                    $orgid = DB::result_first("select orgid from %t where orgname = %s and forgid = %d and `type` = %d",array('orgnization',$patharr[0],0,1));
                    $path = 'dzz:gid_'.$orgid.':'.$path;
                    echo $path;
                    die;
                    $fid = DB::result_first("select fid from %t where path = %s ",array($this->_table,$opath));
                    break;
                case  'o':
                    $orgid = DB::result_first("select orgid from %t where orgname = %s and forgid = %d and `type` = %d",array('organization',$patharr[0],0,0));
                    $path = 'dzz:gid_'.$orgid.':'.$path;
                    $fid = DB::result_first("select fid from %t where path = %s ",array($this->_table,$path));
                    break;
                case  'c':
                    $fid = 'c_'.DB::result_first("select id from %t where uid = %d and catname = %s",array('resources_cat',$uid,$patharr[0]));
                    break;
            }
        }else{
            $upath = 'dzz:uid_'.$uid.':'.$opath;
            if(!$fid =  DB::result_first("select fid from %t where path = %s ",array($this->_table,$upath))){
                $fid =  DB::result_first("select fid from %t where path regexp %s ",array($this->_table,'^dzz:.+:'.$path.'$'));
            }
        }
        return $fid;
    }
    //修改名称时文件夹路径调整
    public function update_path_by_fid($fid,$name){
        global $_G;
        $_G['neworgname'] = $name;
        $path = $this->fetch_pathby_pfid($fid);
        $paths = substr($path,0,-1);//去掉最后斜杠
        $pathname = preg_replace('/^dzz:.+:/','',$paths);//取出路径部分
        $patharr = explode('/',$pathname);//分割成数组
        $content = end($patharr);//取得目录最后一级名字
        $newpath = preg_replace_callback('/(.+?)'.$content.'$/',function($m){
            return $m[1].getglobal('neworgname').'/';
        },$paths);
        if($content != $name) $newpath = str_replace($content,$name,$path);
        else return true;
        $regpath = self::path_transferred_meaning($path);
        $sql = "update %t set path = replace(path,%s,%s) where path regexp %s";
        if(DB::query($sql,array($this->_table,$path,$newpath,'^'.$regpath.'.*'))){
            return true;
        }else{
            return false;
        }
    }
    //修改文件位置时
    public function update_pathdata_by_fid($fid,$ofid,$noself = false){
        if($paths = $this->fetch_pathby_pfid($fid,true)){
            $opaths = $this->fetch_pathby_pfid($ofid,true);
            $path = dirname($paths['path']).'/';
            //$path = self::path_transferred_meaning($path);
            $opath = $opaths['path'];
            $pathkey = explode('-',$paths['pathkey']);
            array_pop($pathkey);
            $pathkey=implode('-',$pathkey);
            $opathkey = $opaths['pathkey'];
            $sql = "update %t set path = replace(path,%s,%s),pathkey = replace(pathkey,%s,%s) where path regexp %s";
            $paths['path'] = self::path_transferred_meaning($paths['path']);
            if($noself) $likepath = $paths['path'].'.+';
            else $likepath = $paths['path'].'.*';
            if(DB::query($sql,array($this->_table,$path,$opath,$pathkey,$opathkey,'^'.$likepath))){
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }


    }
    //转义查询语句当中的path
    public function path_transferred_meaning($path){
        return str_replace(array('\'','(',')','+','^','$','{','}','[',']','#'),array("\'",'\(','\)','\+','\^','\$','\{','\}','\[','\]','\#'),$path);
    }
    //查询当前目录及其下级的fid
    public function get_child_fids($fid){
        $path = self::fetch_pathby_pfid($fid,true);
        $pathkey = $path['pathkey'];
        $fids = array();
        foreach(DB::fetch_all("select fid from %t where pathkey regexp %s",array($this->_table,'^'.$pathkey.'.*')) as $v){
            $fids[] = $v['fid'];
        }
        return $fids;
    }
    //获取文件夹的目录信息(路径及所在位置的顶级目录fid,其中路径不包含顶级目录)
    public function get_dirinfo_by_fid($fid){
        $result = array();
        $pathkeys = self::fetch_pathby_pfid($fid,true);
        $fids = str_replace('_','',$pathkeys['pathkey']);
        $fids = explode('-',$fids);
        $result['pfid'] = $fids[0];
        $path = preg_replace('/^dzz:(.+?):/','',$pathkeys['path']);
        $patharr = explode('/',$path);
        unset($patharr[0]);
        $result['path'] = implode('/',$patharr);
        return $result;

    }
    public function parse_path_get_rootdirinfo($path){
        $dirpath = explode('/',$path);
        $rootpath = $dirpath[0].'/';//根目录路径
        $rootfid = DB::result_first("select fid from %t where path = %s",array($this->_table,self::path_transferred_meaning($rootpath)));
        if(!$rootfid){
            return false;
        }
        $path = str_replace($rootpath,'',$path);
        return array('path'=>$path,'pfid'=>$rootfid);
    }
}