<?php 
	# Host do FTP
	$FTP_HOST		= "seu-host.com.br";

	# Usuário do FTP
	$FTP_USER		= "usuario";

	# Usuário do FTP
	$FTP_PASS		= "su@senha!";

	# Caminho até o diretorio do FTP
	$FTP_ROOT		= "caminho/do/ftp/para/a/pasta/a/sincronizar";

	# Quantidade de downloads simultaneos
	$FTP_SLOTS 		= 10;



	###############################################
	# Mexa, mas só se souber o que está fazendo.
	###############################################

	# Pasta onde os arquivos serão baixados
	$FTP_DOWNLOAD	= __DIR__."/download";

	# Pasta de cache dos já baixados
	$FTP_DOWNLOADED	= __DIR__."/.cache";

	# Pasta onde são armazenados os finalizados (opcional)
	# $FTP_FINISHED = __DIR__."/finalizados";

	# Arquivo que impede o script de executar 2 vezes
	define("PID", __DIR__ . "/.pid");

	# Tempo de espera para matar o PID e executar um novo
	define("PID_WAIT_TIME", 60);

	# Onde escrever o log (opcional)
	# define("LOG", "/caminho/dos/logs");
?>