<?php
  define("PATH_ROOT", dirname(__DIR__));

  define("SERVER_ROOT", $_SERVER['DOCUMENT_ROOT']);
  define("SERVER_SPRITES", $_SERVER['DOCUMENT_ROOT'] . "/images");

  $Host = $_SERVER['HTTP_HOST'] ?? 'pokemon.sauva.internal';

  $Forwarded_Proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  $Forwarded_Proto = trim(explode(',', $Forwarded_Proto)[0]);

  if ( $Forwarded_Proto === 'https' )
  {
    $Scheme = 'https';
  }
  elseif ( $Forwarded_Proto === 'http' )
  {
    $Scheme = 'http';
  }
  else
  {
    $Scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'
      ? 'https'
      : 'http';
  }

  define('LOCAL', $Host === 'localhost' || str_starts_with($Host, 'localhost:'));

  define("DOMAIN_ROOT", $Scheme . "://" . $Host);
  define("DOMAIN_SPRITES", DOMAIN_ROOT . "/images");
