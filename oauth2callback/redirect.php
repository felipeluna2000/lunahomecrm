<?php
	if(isset($_REQUEST['id'])){
        echo '<script>try { if(window.opener && window.opener.afterRedirect) window.opener.afterRedirect('.  $_REQUEST['id'] . '); } catch(e) { console.error(e); } finally { window.close(); }</script>';
	} else {
        echo '<script>try { if(window.opener && window.opener.afterRedirect) window.opener.afterRedirect(); } catch(e) { console.error(e); } finally { window.close(); }</script>';
	}
	exit;
?>