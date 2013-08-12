#!/usr/bin/php
<?php
	/*
	Host: ftp.andregumieri.com
	Usuário: agfteste
	Senha: 1qazZAQ!
	*/
	error_reporting(E_ERROR | E_PARSE);

	/*define("FTP_HOST", "ftp.andregumieri.com");
	define("FTP_USER", "andfre24");
	define("FTP_PASS", "nw9RztHm");
	define("FTP_ROOT", "andregumieri.com/v2/_hml/images");*/
	if(!@include 'config.php') die("Arquivo de configuração config.php não encontrado.\n\n");


	echo "\n\n\n";
	echo "**********************************\n";
	echo "Iniciado em " . date("d/m/Y H:i:s") . "\n";
	echo "**********************************\n\n";

	if(file_exists(PID)) {
		$timepid = file_get_contents(PID);
		$time_diff = mktime()-$timepid;

		if($time_diff<PID_WAIT_TIME) die("Já existe um processo em andamento. Tente novamente em " . (PID_WAIT_TIME-$time_diff) . " segundos.\n");
	}

	file_put_contents(PID, mktime());




	if(!file_exists(DOWNLOAD)) mkdir(DOWNLOAD, 0755, true);
	if(!file_exists(DOWNLOAD)) die("Não foi possível criar pasta de download\n");

	if(!file_exists(DOWNLOADED)) mkdir(DOWNLOADED, 0755, true);
	if(!file_exists(DOWNLOADED)) die("Não foi possível criar pasta de baixados\n");

	function listaRecursiva($resource, $directory) { 
		$files = array();
		$dirs = array();
		$files_complete = array();
		$entradas = ftp_rawlist($resource, $directory, TRUE);
		foreach($entradas as $entrada) {
			if(empty($entrada)) continue;
			if(substr($entrada, -1)==":") {
				$directory = substr($entrada, 0, -1);
				continue;
			}
			//echo $entrada . "\n";

			$item = array();
			$chunks = preg_split("/\s+/", $entrada); 
			list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks; 
			array_splice($chunks, 0, 8);
			$filename = $directory . "/" . implode(" ", $chunks);
			//echo print_r($chunks) . "\n";

			//$files[] = $directory.'/'.$entrada;

			if($item['rights']{0}=="d") {
				$dirs[] = $filename;
			} else {
				$files[] = $filename;
				$files_complete[] = array("file"=>$filename, "size"=>$item['size']);
			}
		}

		return array("dirs"=>$dirs, "files"=>$files, "files_complete"=>$files_complete);
	}


	function criaConexao($localFile, $remoteFile) {
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $remoteFile); #input
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FILE, $localFile); #output
		curl_setopt($curl, CURLOPT_USERPWD, FTP_USER . ":" . FTP_PASS);	
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		return $curl;
	}



    // Faz login
    echo "*** Conexao ***\n";
	$conns = array();
	$slots = array();
	$slotFile = array();
    for($x=0; $x<SLOTS; $x++) {
    	//$conns[$x] = ftp_connect(FTP_HOST);
		//ftp_login($conns[$x], FTP_USER, FTP_PASS);
		$slots[$x] = null;
		$slotFile[$x] = null;
		//echo "Slot " . ($x+1) . " conectado\n";
		//ftp_set_option($conns[$x], FTP_TIMEOUT_SEC, 86400);
    }
    $conn_id = ftp_connect(FTP_HOST);
    ftp_login($conn_id, FTP_USER, FTP_PASS);
    ftp_set_option($conn_id, FTP_TIMEOUT_SEC, 86400);
	echo "\n";


	// Pega todas as entradas
	$lista = listaRecursiva($conn_id, FTP_ROOT);

	// Mata a conexão depois de pegar a lista
	ftp_close($conn_id);


	// Cria pastas
	echo "*** Criar diretorios ***\n";
	$base = DOWNLOAD.'/';
	@mkdir($base.str_replace("./", "", FTP_ROOT."/"), 0755, true);
	@mkdir(DOWNLOADED.'/'.str_replace("./", "", FTP_ROOT."/"), 0755, true);
	foreach($lista['dirs'] as $file) {
		if(strpos($file, " -> ") !== false) continue;
		if(file_exists($base.$file)) continue;

		if(!@mkdir($base.$file, 0755, true)) {
			echo "[Erro Diretorio] {$base}{$file}\n";
		} else {
			echo "Diretorio Criado: {$base}{$file}\n";
		}
		mkdir(DOWNLOADED.'/'.$file, 0755, true);
	}
	echo "\n";



	echo "*** Criar lista de download ***\n";
	$baixar = array();
	foreach($lista['files_complete'] as $file_complete) {
		$file = $file_complete['file'];
		$size = $file_complete['size'];
		if(strpos($file, " -> ") !== false) continue;

		if(file_exists($base.$file) && filesize($base.$file)==intval($size)) {
			echo "[Ignorando] {$base}{$file}\n";
			continue;
		}

		if(file_exists(DOWNLOADED.'/'.$file)) {
			echo "[Ja baixado] {$base}{$file}\n";
			continue;
		}

		$baixar[] = $file;
	}
	echo "\n";


	echo "*** Processo de download ***\n";

	// Cria o Multi CURL
	$mh = curl_multi_init();


	$totalBaixar = count($baixar);
	$downloadControle = array();
	$baixados = 0;
	$slots = SLOTS;
	if($slots>$totalBaixar) $slots = $totalBaixar;

	// adiciona um arquivo por slot
	for($x=0; $x<$slots; $x++) {
		$arquivo = array_shift($baixar);
		$url = "ftp://" . FTP_HOST . "/" . $arquivo;
		$key = md5($url);

		//$fo = null;
		$fo = fopen(DOWNLOAD."/".$arquivo, 'w');
		$downloadControle[$key] = array("ftp_url"=>$url, "local_file"=>DOWNLOAD."/".$arquivo, "file_handle"=>$fo, "arquivo"=>$arquivo);

		$c = criaConexao($fo, $url);
		curl_multi_add_handle($mh,$c);

		echo mktime() . " Iniciando {$arquivo} - " . $fo . "\n";
	}

	//print_r($downloadControle);
	//unlink(PID);die();



	// Executa o download
	$running=null;
	//execute the handles
	do {
		while(($execrun = curl_multi_exec($mh, $running)) == CURLM_CALL_MULTI_PERFORM);
		if($execrun != CURLM_OK) break;

		while($done=curl_multi_info_read($mh)) {
			$info = curl_getinfo($done['handle']);
			if($info['http_code']>=200 && $info['http_code']<300) {
				curl_multi_remove_handle($mh, $done['handle']);
				//$output = curl_multi_getcontent($done['handle']);
				$baixadoControle = $downloadControle[md5($info['url'])];
				//print_r($baixadoControle);
				//print_r($baixadoControle);

				// Fecha o arquivo
				echo mktime() . " Finalizado " . $baixadoControle['local_file'] . " - " . gettype($baixadoControle['file_handle'])  . "\n";
				fclose($baixadoControle['file_handle']);
				//file_put_contents($baixadoControle['local_file'], $output);
				file_put_contents(DOWNLOADED.'/'.$baixadoControle['arquivo'], mktime());
				
				

				// Coloca outro para baixar
				if($baixar) {
					$arquivo = array_shift($baixar);
					$url = "ftp://" . FTP_HOST . "/" . $arquivo;
					$key = md5($url);

					$fo = null;
					$fo = fopen(DOWNLOAD."/".$arquivo, 'w');
					$downloadControle[$key] = array("ftp_url"=>$url, "local_file"=>DOWNLOAD."/".$arquivo, "file_handle"=>$fo, "arquivo"=>$arquivo);

					$c = criaConexao($fo, $url);
					curl_multi_add_handle($mh,$c);

					echo mktime() . " Iniciando {$arquivo} - " . $fo . "\n";
				}

				
			} else {
				echo "[FALHA] " . $downloadControle[md5($info['url'])]['local_file'] . "\n";
			}
		}
	} while($running);

	
	


	/*while($totalBaixar!=$baixados) {
		file_put_contents(PID, mktime());
		
		foreach($slots as $num=>&$ret) {
			//echo "Slot {$num} = {$ret}\n";
			if(is_null($ret) && $baixar) {
				$arquivo = array_shift($baixar);
				$slotFile[$num] = $arquivo;
				$ret = "FTP_MOREDATA";
				$comando = str_replace(" ", "\ ", __DIR__)."/do_curl.sh " . FTP_USER . " " . FTP_PASS . " " . FTP_HOST . " {$arquivo} " . str_replace(" ", "\ ", $base.$arquivo) . " .slot{$num} > /dev/null &";
				//echo $comando; die();
				exec($comando);
				echo "[$num] Iniciando {$arquivo}\n";
			} elseif ($ret=="FTP_FINISHED") {
				$arquivo = $slotFile[$num];
				echo "[$num] Finalizado {$arquivo}\n";
				file_put_contents(DOWNLOADED.'/'.$arquivo, mktime());
				$ret = null;
				$slotFile[$num] = null;
				unlink(".slot{$num}");
				$baixados++;
			} elseif ($ret=="FTP_FAILED") {
				$arquivo = $slotFile[$num];
				echo "[$num] [FALHA] {$arquivo}\n";
				$ret = null;
				$slotFile[$num] = null;
				$baixados++;
			} elseif(!is_null($ret)) {
				$ret = trim(file_get_contents(".slot{$num}"));
				//$ret = ftp_nb_continue($conns[$num]);
			}
		}

		sleep(1);

		//echo "while {$baixados}\n";
		//$baixados++;

	}*/
	echo "\n";


	/*echo "*** Desconectando ***\n";
	for($x=0; $x<SLOTS; $x++) {
		ftp_close($conns[$x]);
    }*/
	unlink(PID);
    echo "\n";

    //sleep(30);

	echo "\nFIM - " . date("d/m/Y H:i:s") . "\n\n";
	curl_multi_close($mh);	
?>