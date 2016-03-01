<?php
class SNAPSHOT
{
    var $snapshot_file;
    var $root_path;
    var $target_dir;
    var $excludes;
    var $password;
    var $tool_url;
    var $use_cron;
    var $admin_email;
    var $version;
    
    function __construct()
    {
        include_once('config.inc.php');
        $pathinfo = explode('/',$_SERVER['SCRIPT_NAME']);
        array_pop($pathinfo);
        $scheme = !isset($_SERVER['HTTPS']) ? 'http://' : 'https://';
        $this->tool_url = $scheme . $_SERVER['HTTP_HOST'] . join('/', $pathinfo) . '/';
        session_start();
        header("Content-type: text/html; charset=utf-8");
        if(function_exists('date_default_timezone_set')) date_default_timezone_set($timezone);
        mb_detect_order('SJIS-win,EUCJP-win,UTF-8,JIS,ASCII');
        $this->root_path     = rtrim(str_replace('\\','/', dirname(__FILE__)), '/');
        $this->snapshot_file = $this->root_path . "/{$snapshot_txt}";
        $this->target_dir    = $this->set_target_dir($target_dir);
        $this->excludes      = $excludes;
        $this->password      = $password;
        $this->use_cron      = $use_cron;
        $this->admin_email   = $admin_email;
    }
    
    function run()
    {
        $this->msg = $this->getMsgFromSession();
        
        if(isset($_POST['password']))
        {
            $this->login();
            $this->redirectTop();
            exit;
        }
        
        if(!$this->isLoggedIn())
        {
            echo $this->showLoginForm();
            exit;
        }
        
        if(isset($_REQUEST['action'])) $action = $_REQUEST['action'];
        else                           $action = '';
        
        switch($action)
        {
            case 'check':
                $_SESSION['msg'] = $this->check_snapshot();
                $this->redirectTop();
                break;
            case 'snapshot':
                $_SESSION['msg'] = $this->make_snapshot('put');
                $this->redirectTop();
                break;
            case 'download':
                $_SESSION['msg'] = $this->make_snapshot('download');
                $this->redirectTop();
                break;
            case 'logout':
                unset($_SESSION['status']);
                $_SESSION['msg'] = '<p class="ok">ログアウトしました。</p>';
                $this->redirectTop();
                break;
            case 'cron':
                if($this->use_cron!=='yes')
                {
                    $_SESSION['msg'] = 'cron利用は無効に設定されています。';
                    $this->redirectTop();
                }
                elseif(empty($this->admin_email))
                {
                    $_SESSION['msg'] = '送信先メールアドレスが設定されていません。';
                    $this->redirectTop();
                }
                else
                {
                    $report = $this->check_snapshot();
                    $report = strip_tags($report);
                    $date = date('Y-m-d H:i:s');
                    $ua = (isset($_SERVER['HTTP_USER_AGENT'])) ? htmlspecialchars($_SERVER['HTTP_USER_AGENT']) : '-';
                    $rs = $this->send_report($report,$date,$ua);
                    echo $rs;
                    exit;
                }
                break;
            default:
                if(!is_writable($this->snapshot_file))
                {
                    $msg = $this->snapshot_file . ' に書き込み権限を与えてください。';
                }
                elseif(filesize($this->snapshot_file) < 5)
                {
                    $msg = '<p class="ng">最初に現時点のスナップショットを記録してください。</p>';
                }
                else
                {
                    $msg = '操作を選んでください。環境によっては数十秒かかることがありますが、表示が変わるまでお待ちください。';
                    $timestamp = date('Y-m-d H:i:s ',filemtime($this->snapshot_file));
                    $msg .= '<br />スナップショットの日付：' . $timestamp;
                }
        }
        
        $tpl = $this->getChunk('template_default.html');
        $ph['content'] = $this->get_chunk();
        $ph['msg']     = $this->msg;
        echo $this->parseText($tpl, $ph);
    }
    
    function getMsgFromSession()
    {
        if(isset($_SESSION['msg']) && $_SESSION['msg']!=='')
        {
            $msg = $_SESSION['msg'];
            unset($_SESSION['msg']);
        }
        else $msg = '';
        return $msg;
    }
    
    function redirectTop()
    {
        header('Location: ' . $this->tool_url);
        exit;
    }
    function isLoggedIn()
    {
        return (isset($_SESSION['status']) && $_SESSION['status']=='online');
    }
    
    function parseText($tpl, $ph)
    {
        $i = 0;
        while($i < 10)
        {
            $bt = md5($tpl);
            foreach($ph as $k=>$v)
            {
                $k = "[+{$k}+]";
                $tpl = str_replace($k, $v, $tpl);
            }
            $i++;
            if(strpos($tpl,'[+')===false) break;
            if(md5($tpl)==$bt)            break;
        }
        return $tpl;
    }
    
    function send_report($report,$date,$ua)
    {
        mb_language('Japanese');
        mb_internal_encoding('UTF-8');
        
        $subject = '改竄チェックレポート';
        $body    = $date . "\n" . $ua . "\n\n" . $report;
        $rs = mb_send_mail($this->admin_email, $subject, $body);
        return ($rs!==false) ? '改竄チェックレポートを送信しました。' : '送信に失敗しました。';
    }
    
    function login()
    {
        if(isset($_POST['password']) && $_POST['password'] == $this->password)
        {
            $_SESSION['status'] = 'online';
            $_SESSION['msg'] = '<p class="ok">ログインしました。</p>';
        }
        elseif(isset($_POST['password']) && $_POST['password'] !== $this->password)
            $_SESSION['msg'] = '<p class="ng">パスワードが違います。</p>';
        else
            $_SESSION['msg'] = '<p class="ng">ログインしていません。</p>';
    }
    
    function get_chunk()
    {
        $ph['btn_check'] = (4 < filesize($this->snapshot_file)) ? $this->getChunk('parts_btn_check.txt') : '';
        $tpl = $this->parseText($this->getChunk('parts_btns.txt'), $ph);
        return $tpl;
    }
    function showLoginForm() {
        $ph['content'] = $this->parseText($this->getChunk('parts_loginform.txt'),array('tool_url'=>$this->tool_url));
        $ph['msg']     = $this->msg;
        $tpl = $this->getChunk('template_default.html');
        return $this->parseText($tpl,$ph);
    }
    
    function set_target_dir($target_dir='')
    {
        if($target_dir == '')       $path = dirname(__FILE__);
        elseif($target_dir[0]=='/') $path = getenv('DOCUMENT_ROOT') . $target_dir;
        else $path = realpath($target_dir);
        $path = str_replace('\\','/',$path);
        $rs = file_exists($path);
        if($rs!==false) $this->target_dir = $path;
        else exit;
        return $path;
    }
    
    function check_snapshot()
    {
        $filenotfound = array();
        $filesfailed  = array();
        
        $_ = file_get_contents($this->snapshot_file);
        $lines = explode("\n", $_);
        
        foreach($lines as $line)
        {
            if(strpos($line,'-:-')!==FALSE) list($md5sum,$path) = explode('-:-',$line,2);
            else                            continue;
            
            $md5sum = trim($md5sum);
            $path   = trim($path);
            
            $real_path = $this->target_dir . $path;
            
            if(!is_file($real_path))                $filenotfound[] = $path;
            elseif($md5sum != md5_file($real_path)) $filesfailed[]  = date('Y-m-d H:i:s ', filemtime($real_path)) . $path;
        }
        
        $tmp = array();
        if(count($filenotfound))
        {
            $tmp[] = "<h2>見つからないファイル</h2>\n";
            $tmp[] = '<ul class="ng">' . "\n<li>" . join("</li>\n<li>", $filenotfound) . "</li>\n</ul>";
        }
        if(count($filesfailed))
        {
            $tmp[] = "<h2>改竄の可能性があるファイル</h2>\n";
            $tmp[] = '<ul class="ng">' . "\n<li>" . join("</li>\n<li>", $filesfailed) . "</li>\n</ul>";
        }
        
        if($tmp) return join("\n", $tmp);
        else     return '<h2>検査結果</h2>' . "\n" . '<p class="ok">問題ありません。</p>';
    }
    
    function make_snapshot($mode='put')
    {
        $output = '';
        $msg = '';
        
        $tmp = $this->get_recursive_file_list($this->target_dir);
        if(count($tmp) <= 1 )
        {
            echo 'ファイルがありません';
            return false;
        }
        
        foreach($tmp as $file)
        {
            if(is_dir($file)) continue;
            
            $md5sum = md5_file($file);
            $file = str_replace($this->target_dir,'',$file);
            $line[] = $md5sum . '-:-' . $file;
        }
        $output = join("\n",$line);
        
        if($mode=='download') $this->transferFile($output);
        elseif($mode!=='put') return;
        
        $rs = file_put_contents($this->snapshot_file, $output);
        if($rs) $msg = '<p class="ok">スナップショットを更新しました。</p>';
        else    $msg ='<p class="ng">スナップショットを更新できませんでした。パーミッションを確認してください。</p>';
        return $msg;
    }
    
    function transferFile($text)
    {
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private',false);
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="checksum.dat"' );
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($text));
        exit($text);
    }
    
    function get_recursive_file_list($path, $depth = 0 )
    {
        $path = rtrim($path,'/') . '/';
        $file_list = array ();
        $file_list[] = $path;
        $files = scandir($path);
        foreach($files as $file_name)
        {
            if($file_name == '.' || $file_name == '..')                  continue;
            if($this->isExcludedFile($file_name)) continue;
            
            $file_path = $path . $file_name;
            if(is_file($file_path))
            {
                $file_list[] = $file_path;
            }
            elseif(0 <= $depth && is_dir($file_path))
            {
                $result  = $this->get_recursive_file_list("{$file_path}/", $depth + 1 );
                $file_list = array_merge($file_list , $result);
            }
        }
        
        if($depth == 0) natcasesort($file_list);
        
        return($file_list);
    }
    
    function isExcludedFile($file)
    {
        // strip the path from the file
        if( empty($this->excludes)) return false;
        
        $file_name = end(explode('/',$file));
        foreach($this->excludes as $excl)
        {
            if(@preg_match("/{$excl}/i", $file_name))
                return true;
        }
        return false;
    }
    
    function getChunk($tpl_name)
    {
        $src = file_get_contents($this->root_path . "/assets/snippets/{$tpl_name}");
        return $src;
    }
}
