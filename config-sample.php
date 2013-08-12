<?php 
	define("FTP_HOST", "seu-host.com.br");
	define("FTP_USER", "usuario");
	define("FTP_PASS", "su@senha!");
	define("FTP_ROOT", "caminho/do/ftp/para/a/pasta/a/sincronizar");
	define("SLOTS", 10); # Quantidade de conexões simultaneas



	# Mexa, mas só se souber o que está fazendo.
	define("DOWNLOAD", __DIR__ . "/download");	# Pasta onde os arquivos serão baixados
	define("DOWNLOADED", __DIR__ . "/.cache");	# Pasta que faz o cache dos arquivos já baixados
	define("PID", __DIR__ . "/.pid");			# Arquivo que impede o script de executar 2 vezes
	define("PID_WAIT_TIME", 60);				# Tempo de espera para matar o PID e executar um novo
?>