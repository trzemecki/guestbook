<?php
date_default_timezone_set("Europe/London");  # TODO allow to set in settings panel

$EMOTICONS = array(
    ';|' => 'emo1',
    ':|' => 'emo2',
    '{no}' => 'emo3',
    '{yes}' => 'emo4',
    ':)' => 'emo5',
    ':}' => 'emo6',
    ':]' => 'emo7',
    ';)' => 'emo8',
    ':O' => 'emo9',
    ':?' => 'emo10',
    ':[' => 'emo11',
    'X|' => 'emo12',
    ':(' => 'emo13',
    '{|' => 'emo14',
    ';(' => 'emo15',
    ':{' => 'emo16',
);

class Database {
    const PATH = 'db.sqlite';
    const NOT_APPROVED = 1;
    const APPROVED = 2;
    const BIN = 3;
    
    private $pdo = null;
    private $location = '';
    
    public function __construct($prefix='../') {
        $this->location = $prefix . $this::PATH;
        $initialized = file_exists($this->location);
        $this->pdo = new PDO('sqlite:' . $this->location);
        
        if(!$initialized) {
            $this->init_db();
        }
    }
    
    private function init_db() {
        $this->pdo->exec("
            CREATE TABLE Users (
                ID INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, 
                UserName TEXT NOT NULL UNIQUE, 
                Password TEXT NOT NULL,
                LastValidLoginTime INTEGER NOT NULL,
                LastFailedLoginTime INTEGER
            );");
        
        $this->pdo->exec("
            INSERT INTO Users
            VALUES (NULL, 'admin', '" . sha1('admin') . "', strftime('%s', 'now'), NULL);
            ");

        $this->pdo->exec("
            CREATE TABLE Entries (
                ID INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                IP TEXT,
                Kind INTEGER NOT NULL,
                Name TEXT NOT NULL,
                Email TEXT,
                Message TEXT,
                CreationTime INTEGER  NOT NULL,
                ModificationTime INTEGER  NOT NULL
            );");
        
        $this->pdo->exec("
            CREATE TABLE FailedLogins (
                ID INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                IP TEXT UNIQUE,
                FailCount INTEGER,
                LastTryTime INTEGER
            );");
        
    }
    
    public function check_user($user_name, $password){
        $request = $this->pdo->prepare("SELECT Password FROM Users WHERE UserName=:user_name;");
        $request->bindParam(':user_name', $user_name, PDO::PARAM_STR, 50);
        $request->execute();
        $valid_password = $request->fetch()['Password'];
        return !empty($valid_password) and sha1($password) === $valid_password;
    }
    
    public function change_user($user_name, $new_user_name, $new_password) {
        $sha_password = sha1($new_password);
        $request = $this->pdo->prepare("UPDATE Users SET UserName=:user_name, Password=:password WHERE UserName=:prev_name;");
        $request->bindParam(':user_name', $new_user_name);
        $request->bindParam(':password', $sha_password);
        $request->bindParam(':prev_name', $user_name);
        $request->execute();
    }
    
    public function get_next_login_try_time() {
        $ip = $this->get_ip();
        
        $request = $this->pdo->prepare("
            SELECT FailCount, LastTryTime
            FROM FailedLogins
            WHERE IP=:ip;
            ");
        $request->bindParam(':ip', $ip);
        $request->execute();
        
        $data = $request->fetch();

        if ($data !== false && $data['FailCount'] > 3) {
            return $data['LastTryTime'] + ($data['FailCount'] - 3) * 5 * 60;
        }  else {
            return 0;
        }
    }
    
    public function get_last_login_time($user_name) {
        $request = $this->pdo->prepare("
            SELECT LastValidLoginTime, LastFailedLoginTime
            FROM Users 
            WHERE UserName=:user;");
        $request->bindParam(':user', $user_name);
        $request->execute();
        
        return $request->fetch();
    }
    
    public function register_login_success($user_name) {
        $ip = $this->get_ip();
        
        $request = $this->pdo->prepare("
            DELETE FROM FailedLogins
            WHERE IP=:ip;
            ");
        $request->bindParam(':ip', $ip);
        $request->execute();
        
        $request = $this->pdo->prepare("
            UPDATE Users 
            SET LastValidLoginTime=strftime('%s', 'now')
            WHERE UserName=:user;
            ");
        $request->bindParam(':user', $user_name);
        $request->execute();
    }
    
    public function register_login_fail($user_name) {
        $ip = $this->get_ip();

        $request = $this->pdo->prepare("
            UPDATE Users 
            SET LastFailedLoginTime=strftime('%s', 'now')
            WHERE UserName=:user;
            ");
        $request->bindParam(':user', $user_name);
        $request->execute();
        
        $request = $this->pdo->prepare("
            SELECT FailCount
            FROM FailedLogins 
            WHERE IP=:ip;");
        $request->bindParam(':ip', $ip);
        $request->execute();
        
        $fail_count = $request->fetch()['FailCount'];
            
        if(empty($fail_count)) {
            $request = $this->pdo->prepare("
                INSERT INTO FailedLogins 
                VALUES (NULL, :ip, 1, strftime('%s', 'now'));
                ");
            $request->bindParam(':ip', $ip);
            $request->execute();
        } else {
            $request = $this->pdo->prepare("
                UPDATE FailedLogins 
                SET FailCount=:fail_count, LastTryTime=strftime('%s', 'now')
                WHERE IP=:ip;
                ");
            $request->bindValue(':fail_count', $fail_count + 1, PDO::PARAM_INT);
            $request->bindParam(':ip', $ip);
            $request->execute();
        }
    }
    
    public function create_new_entry($name, $email, $message) {
        $request = $this->pdo->prepare("
            INSERT INTO Entries
            VALUES (NULL, :ip, :kind, :name, :email, :message, strftime('%s', 'now'), strftime('%s', 'now'));
            ");
        $request->bindValue(':kind', $this::NOT_APPROVED, PDO::PARAM_INT);
        $request->bindValue(':ip', $this->get_ip());
        $request->bindParam(':name', $name);
        $request->bindParam(':email', $email);
        $request->bindParam(':message', $message);
        $request->execute();
    }
    
    public function get_entries($kind=Database::APPROVED) {
        $request = $this->pdo->prepare("
            SELECT ID, Name, Email, Message, CreationTime, ModificationTime 
            FROM Entries 
            WHERE Kind=:kind
            ORDER BY CreationTime DESC;
            ");
        $request->bindParam(':kind', $kind, PDO::PARAM_INT);
        $request->execute();
        
        return $request->fetchAll();
    }
    
    public function move_entries($ids, $target) {
        $ids_str = '(' . join(', ', $ids) . ')';
        $request = $this->pdo->prepare("
            UPDATE Entries 
            SET Kind=:kind, ModificationTime=strftime('%s', 'now')
            WHERE ID IN $ids_str;
            ");
        $request->bindParam(':kind', $target, PDO::PARAM_INT);
        $request->execute();
    }
    
    public function remove_entries($ids) {
        $ids_str = '(' . join(', ', $ids) . ')';
        $this->pdo->exec("
            DELETE FROM Entries
            WHERE ID IN $ids_str;
            ");
    }
    
    private function get_ip(){
        return filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_SANITIZE_SPECIAL_CHARS);
    }
}


function format_reply($status, $message, $details='') {
    return '
    <div id="status" class="alert alert-' . $status . ' alert-dismissible fade show mt-4 mb-4" role="alert">
      <h4 class="alert-heading">' . $message . '</h4>
      <p>' . $details . '</p>
      <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>';
}

function format_record(&$record) {
    return '<div class="container record">
        <div class="row">
            <div class="col-12">' . replace_emoticons($record['Message']) . '</div>
        </div>
        <div class="row">
            <div class="col-sm-5 col-md-4">' . date('d.m.y H:i:s', $record['CreationTime']) . '</div>
            <div class="col-sm-5 col-md-4">' . $record['Name'] . (empty($record['Email']) ? '' : ' (' . $record['Email'] . ')') . '</div>
        </div>
    </div>';
}


function replace_emoticons($text) {
    global $EMOTICONS;
    
    $emoticon_path = function($v)  {
        return '<img src="images/emoticons/' . $v . '.gif" />';
    };

    return str_replace(
        array_keys($EMOTICONS), array_map($emoticon_path, array_values($EMOTICONS)), $text
    );
}
