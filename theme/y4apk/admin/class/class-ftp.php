<?php
class FTP {
    private $ftp_server;
    private $ftp_port;
    private $ftp_user;
    private $ftp_pass;
    private $ftp_directory;
    private $ftp_access_url;
    private $conn_id;
    
    public function __construct() {
        $this->ftp_server   = y4apk_options( 'ftp_server_ip', true );
		$this->ftp_port     = y4apk_options( 'ftp_port', true ) ? y4apk_options( 'ftp_port', true ) : 21;
		$this->ftp_user 	= y4apk_options( 'ftp_username', true );
		$this->ftp_pass 	= y4apk_options( 'ftp_password', true );
		$this->ftp_directory = y4apk_options( 'ftp_directory', true ) ? trailingslashit(y4apk_options( 'ftp_directory', true )) : '';
		$this->ftp_access_url = untrailingslashit( y4apk_options( 'ftp_url', true ) );
    }

    public function Upload( $file_path, $filename ) {

        $this->conn_id = @ftp_connect( $this->ftp_server , $this->ftp_port, 30 );
        
        if( !$this->conn_id ) {
            return array('error' => sprintf( __( 'Could not connect to "%s". Check again!', 'y4apk' ), $this->ftp_server ) );
        }
    
        if( @ftp_login( $this->conn_id, $this->ftp_user, $this->ftp_pass ) ) {
    
            if(!ftp_pasv($this->conn_id, true)) {
                return array('error' => __( 'Cannot switch to passive mode in FTP', 'y4apk' ));
            } else {
                ftp_pasv($this->conn_id, true);
            }
    
            $ret = ftp_nb_put($this->conn_id, $this->ftp_directory.$filename, $file_path, FTP_BINARY);
    
            while( $ret == FTP_MOREDATA ) {
                $ret = ftp_nb_continue($this->conn_id);
            }
            if( $ret != FTP_FINISHED ) {
                $result = array('error' => __( 'Upload failed!', 'y4apk' ). ' - ' . error_get_last()['message']);
            } else {
                $link_download = $this->ftp_access_url.'/'.$filename;
                $result = array('url' => $link_download);
            }
        } else {
            $result = array('error' => __( 'Incorrect server data. Check again', 'y4apk' ) );
        }
    
        ftp_close($this->conn_id);
        return $result;
    }    
}