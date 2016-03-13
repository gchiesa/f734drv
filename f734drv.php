#!bin/php -q
<? 
/*********************************************************
* Copyright 2007, 2007 - Giuseppe Chiesa
*
*
* f734drv is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* f734drv is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with f734drv; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*
* Created by: Giuseppe Chiesa - http://gchiesa.smos.org
*
**
* f734drv
*
* Questo file implementa il driver di base per la gestione del terminale scanner batch
* Datalogic Formula F734. Le funzioni supportate sono la lettura/dump dei dati raccolti con il 
* terminale e la cancellazione dei dati stessi.
*
* REQUISITI:
* Il driver è scritto in PHP4 (o superiori) per garantirne la portabilitÃ  tra diverse piattaforme. 
* Per questo motivo necessitÃ  dell'interprete PHP compilato con il supporto seriale --enable-dio in 
* fase di compilazione.
*
* Il driver funziona da riga di comando. Per ottenere maggiori informazioni digitare:
* 
* f734drv --help
*
* Per segnalazioni di bug o malfunzionamenti contattare <gchiesa@smos.org>
*
*
* @package f734drv
* @author Giuseppe Chiesa <gchiesa@smos.org>
* @version 0.1.0
* @abstract
* @copyright GNU Public License
*/


/* definizioni */
define('ABSTRACT_PRG', 'FORMULA F734 DRIVER');
define('ABSTRACT_VER', '1.0.0');
define('ABSTRACT_CPY', 'Created By Giuseppe Chiesa, <gchiesa@smos.org>');

/* default options */
define('DEFAULT_BAUDS', '9600');
define('DEFAULT_BITS', '7');
define('DEFAULT_PARITY', 'E');
define('DEFAULT_WAIT', 150000);
define('DEFAULT_LOCALE', 'EN');

/* program options */
define('VERBOSE', 1);
define('DEBUG', 2);

/* block lenght */
define('MAXREAD', 1024);

/* checksum modes */
define('LRCC', 1);
define('MOD256', 2);

/* control codes */
define('ESCAPE', 0x1b);
define('STADDR', 0x01);
define('STX', 0x02);
define('ETX', 0x03);
// define('ETB', 0x17);
define('ETB', 0x0d);
define('CR', 0x0d);


/**
 * f734Messages
 *
 * Questa classe implementa le diverse localizzazioni per i messaggi a video del driver
 */
class f734Translator {

   var $lang;
   var $locale;
   var $aMessages;
   
   
   function f734Translator($locale)
   {
      $this->lang = array (   'IT' => array (   'messaggio' => "traduzione in lingua", 
                                                'impossibile_leggere_verifica_cradle' => "impossibile leggere dal terminalino. Verificare che sia ben inserito nel cradle",
                                                'comando_non_supportato' => "Comando %s non supportato\n",
                                                'opzione_sconosciuta_help' => "Opzione $s non riconsciuta. Digita %s --help per maggiori informazioni\n",
                                                'necessaria_opzione_c' => "E' necessario specificare un comando con l'opzione -c . Usare l'opzione -h per ottenere l'help in linea.\n",
                                                'modalita' => "Modalità ",
                                                'download' => "DOWNLOAD",
                                                'cancellazione' => "CANCELLAZIONE",
                                                'periferica' => "Periferica ",
                                                'parita' => "Parità",
                                                'impossibile_aprire_seriale' => "impossibile aprire la seriale %s \n\n",
                                                'terminale' => "Terminale ", 
                                                'record_letti' => "Record Letti ",
                                                'dati_terminalino_eliminati' => "Dati Terminalino Eliminati "
                                             ) ,
                              'EN' => array (   'messaggio' => "translation in target language",
                                                'impossibile_leggere_verifica_cradle' => "Unable to read from scanner. Verify if it's inserted in the cradle.",
                                                'comando_non_supportato' => "Unsupported command. %s \n",
                                                'opzione_sconosciuta_help' => "Unknow option %s. Please type %s --help to get usage info.\n",
                                                'necessaria_opzione_c' => "You must specify the flag -c. Type -h as argument to get usage info. \n",
                                                'modalita' => "Mode ...",
                                                'download' => "DOWNLOAD",
                                                'cancellazione' => "DELETE",
                                                'periferica' => "Device ...",
                                                'parita' => "Parity",
                                                'impossibile_aprire_seriale' => "Unable to read from serial port %s \n\n",
                                                'terminale' => "Scanner ..",
                                                'record_letti' => "Records read ",
                                                'dati_terminalino_eliminati' => "Scanner user data deleted "
                                             )
      			);

      $availLang = array_keys($this->lang);
      if(in_array($locale, $availLang)) 
         $this->locale = $locale;
      else
         $this->locale = DEFAULT_LOCALE;
         
      /* mi preparo per non doverlo ricreare ogni volta l'array degli identificativi dei messaggi */
      $this->aMessages = array_keys($this->lang[$this->locale]);
      
   }
   
   function getMessage($message)
   {
      if(!in_array($message, $this->aMessages))
         return ('warning: messaggio non disponibile');
      else
         return ( $this->lang[$this->locale][$message] );
   }

} // END OF CLASS

   
/**
 * f734Protocol 
 *
 * Questa classe implementa la gestione della comunicazione e protocollo per il terminale 
 * Datalogic FORMULA F734
 */
class f734Protocol {
   
   var $checksumMode;
   var $devModel;
   var $devFirmware;
   var $devRam;
   var $fd;
   var $data;
   var $locale;      /* puntatore a classe per la localizzazione */
   
   /**
    * f734Protocol() : costruttore della classe. Inizializza tutte le proprietÃ  della classe.
    */
   function f734Protocol()
   {  $this->checksumMode = LRCC;
      $this->devModel = '';
      $this->devFirmware = '';
      $this->devRam = '';
      $this->fd = NULL;
   }
   

   function setLocale(&$locale)
   {
      $this->locale = $locale;
   }

   
   /**
    * checksumLRCC($message) : metodo che implementa il controllo di checksum LRCC per alcune 
    *                          configurazioni del terminale F734
    * @parameter string $message : stringa su cui calcolare il checksum
    */
   function checksumLRCC($message)
   {
      $k = 0;
      $checksum = 0;

      for($k=0;$k<strlen($message);$k++) {
         $checksum = $checksum ^ ord($message[$k]);
         
      }
      return chr($checksum);
   }

   /**
    * checksumMOD256($message) : metodo che implementa il controllo di checksum LRCC per alcune 
    *                            configurazioni del terminale F734
    * @parameter string $message : stringa su cui calcolare il checksum
    */
   function checksumMOD256($message)
   {  
      $k = 0;
      $checksum = 0;
      
      for($k=0;$k<strlen($message);$k++) {
         $checksum = $checksum + ord($message[$k]);
      }
      $checksum %= 256;
      
      return sprintf("%02X", $checksum);
   }

   /**
    * packMessage($message) : metodo che implementa l'impacchettamento dati secondo la struttura del 
    *                         protocollo di comunicazione formula F734 
    * @parameter string $message : stringa da codificare
    */
   function packMessage($message)
   {
      /* creo il pacchetto del messaggio a cui accodare poi il checksum */
      $message = chr(STX).chr(STADDR).chr(ESCAPE).$message.chr(ESCAPE).chr(ETX);
      
      /* calcolo il checksum del messaggio */
      if($this->checksumMode == LRCC)
         $checksum = $this->checksumLRCC($message);
      else 
         $checksum = $this->checksumMOD256($message);
         
      // echo "rilevato checksum ".$checksum."\n";
               
      /* pacchettizzo il messaggio */
      return ($message.$checksum.chr(ETB));
   }

   /**
    * parseString($message) : metodo che implementa l'estrazione del messaggio appropriato dal pacchetto 
    *                         ricevuto nella comunicazione tra host e F734
    * @parameter string $message : stringa da decodificare
    */
   function parseString($message)
   {
      $k = 0;
      while($k<strlen($message)) {
         if(ord($message[$k]) == STX) break;
         $k++;
      }
      
      $validText = '';
      $k += 2; /* salto i campi STX e station address */
      while($k<strlen($message)) {
         if(ord($message[$k]) == ETX) break;
         $validText .= $message[$k];
         $k++;
      }
      
      return ($validText);
   }

   /**
    * checkAndDetectModel() : metodo che implementa il controllo e la lettura del modello di terminale 
    *                         collegato.
    */
   function checkAndDetectModel()
   {
      $message = "8";
      $pack = $this->packMessage($message);
      $k = dio_write($this->fd, $pack, strlen($pack));
      usleep(DEFAULT_WAIT);
      $reply = dio_read($this->fd, MAXREAD);
      if($reply=='') die($this->locale->getMessage('impossibile_leggere_verifica_cradle'));
      
      /* reimposto la seriale in modalitÃ  non blocking */
      dio_fcntl($this->fd, F_SETFL, 0);
      
      /*riempio la variabile devModel */
      $this->devModel = $this->parseString($reply);
   }


   /**
    * detectModelData() : metodo che implementa la lettura delle informazioni aggiuntive relative 
    *                     al terminale collegato
    */
   function detectModelData()
   {  
      /* prendo i dati del firmware */
      $message = "8$";
      $pack = $this->packMessage($message);
      $k = dio_write($this->fd, $pack, strlen($pack));
      usleep(DEFAULT_WAIT);
      $reply = dio_read($this->fd, MAXREAD);
      $this->devFirmware = $this->parseString($reply);

      /* prendo i dati della ram */      
      $message = "8*";
      $pack = $this->packMessage($message);
      $k = dio_write($this->fd, $pack, strlen($pack));
      usleep(DEFAULT_WAIT);
      $reply = dio_read($this->fd, MAXREAD);
      $this->devRam = $this->parseString($reply);      
   }
   
   /**
    * downloadData() : metodo che implementa la procedura di download dei dati da terminale connesso all'host e 
    *                  salvataggio degli stessi nella apposita variabile della classe.
    */
   function downloadData()
   {
      $message = "0FORMULA734";
      $pack = $this->packMessage($message);
      $k = dio_write($this->fd, $pack, strlen($pack));
      usleep(DEFAULT_WAIT);
      
      $finish = false;
      $this->data = array();
      while(!$finish) {
         $buff = dio_read($this->fd, MAXREAD);
         if(strstr('<EOT>', $this->parseString($buff))) $finish = true;
         array_push($this->data, $this->parseString($buff));
         usleep(DEFAULT_WAIT);
         dio_write($this->fd, chr(06), 1);
         usleep(DEFAULT_WAIT);
      }
   }

   /**
    * getTotRecords() : metodo che implementa il calcolo dei record totali letti dal dump del terminale
    */
   function getTotRecords()
   {
      list($tmpNull, $totRecords) = explode("-", $this->data[0]);
      return ($totRecords);
   }
      

   /**
    * printData() : metodo che implementa la stampa a video dei dati prelevati dal dump del terminale
    */
   function printData()
   {
      foreach($this->data as $row) {
         if($row[0]=='F' || $row[0]=='<') continue;
         echo $row."\n";
      }
   }

   /**
    * deleteData() : metodo che implementa la procedura di eliminazione dati dal terminale.
    */
   function deleteData()
   {
      $message = "1FORMULA734";
      $pack = $this->packMessage($message);
      $k = dio_write($this->fd, $pack, strlen($pack));
      usleep(DEFAULT_WAIT);
      
      $finish = false;
      while(!$finish) {
         $reply = dio_read($this->fd, MAXREAD);
         if(strstr("<DEL>", $this->parseString($reply))) $finish = true;
         usleep(DEFAULT_WAIT);
         $k = dio_write($this->fd, chr(06), 1);
         usleep(DEFAULT_WAIT);
      }
      
   }

      
} // END OF CLASS 


/**
 * dbg_ppack($packMessage) : funzione di debug. Stampa il contenuto in hex del pacchetto dati passato 
 *                           come argomento.
 * param string $packMessage : stringa contenente il pacchetto da stampare.
 */
function dbg_ppack($packMessage)
{
   $buff = '';

   for($k=0;$k<strlen($packMessage);$k++) {
      if(ord($packMessage[$k]) >= 32 && ord($packMessage[$k]) <= 126 ) 
         $ch = sprintf("[%02x - %s ] ", ord($packMessage[$k]), $packMessage[$k]);
      else 
         $ch = sprintf("[%02x] ", ord($packMessage[$k]));
      $buff .= $ch;
   }

   return $buff;
}

/**
 * dbg_ascii($text) : funzione di debug. Stampa il contenuto del pacchetto filtrando i caratteri ascii 
 *                    non leggibili.
 * param string $text : stringa contenente il pacchetto da stampare.
 */
function dbg_ascii($text)
{
   $buff = '';
   
   for($k=0;$k<strlen($text);$k++) {
      if(ord($text[$k]) >= 32 && ord($packMessage[$k]) <= 126 )
         $ch = sprintf("%s", $text[$k]);
      else
         $ch = '*';

      $buff .= $ch;
   }
   
   return $buff;
}

      
/**
 * usage($programName) : funzione che stampa a video l'help del driver.
 * 
 * param string $programName : stringa contenente il nome del programma invocato.
 */
function usage($programName, $locale)
{

   if($locale == 'IT') {
      echo  "\n".ABSTRACT_PRG." - Ver. ".ABSTRACT_VER."\n".
            "driver per la gestione del terminale scanner portatile FORMULA F734\n".
            "Utilizzo: ".$programName." DEVICE [-o=BAUDS,BITS,PARITY] [-v] [-cREAD|DELETE]\n".
            "\t-o\t\tOpzioni porta seriale\n".
            "\t  \t\tBAUDS:4800,9600,19800\n".
            "\t  \t\tBITS: 7 o 8\n".
            "\t  \t\tPARITY:N=nessuna,E=pari,O=dispari\n".
            "\t-v\t\tModalitÃ  verbosa\n".
            "\t-c\t\tComando: READ legge i dati dal terminale\n".
            "\t  \t\tDELETE cancella il contenuto del terminale\n".
            "\nCopyright (C) 2006 Free Software Foundation, Inc.\n".
            "This is free software. You may redistribute copies of it under the terms of\n".
            "the GNU General Public License <http://www.gnu.org/licenses/gpl.html>.\n".
            "There is NO WARRANTY, to the extent permitted by law.\n".
            "\n".ABSTRACT_CPY."\n\n";
   } else {
      echo  "\n".ABSTRACT_PRG." - Ver. ".ABSTRACT_VER."\n".
            "driver to manage batch barcode scanner FORMULA F734 Datalogic\n".
            "Usage: ".$programName." DEVICE [-o=BAUDS,BITS,PARITY] [-v] [-cREAD|DELETE]\n".
            "\t-o\t\tSerial Port Options\n".
            "\t  \t\tBAUDS:4800,9600,19800\n".
            "\t  \t\tBITS: 7 o 8\n".
            "\t  \t\tPARITY:N=none,E=even,O=odd\n".
            "\t-v\t\tVerbose Mode\n".
            "\t-c\t\tCommand: READ dump data from scanner\n".
            "\t  \t\tDELETE delete data from scanner\n".
            "\nCopyright (C) 2006 Free Software Foundation, Inc.\n".
            "This is free software. You may redistribute copies of it under the terms of\n".
            "the GNU General Public License <http://www.gnu.org/licenses/gpl.html>.\n".
            "There is NO WARRANTY, to the extent permitted by law.\n".
            "\n".ABSTRACT_CPY."\n\n";
   }
   
   return 0;
}


/********************************************************************
** main *************************************************************
********************************************************************/

/* identifico la lingua del sistema */
list($langUsed, $tmpNull) = explode("_", getenv("LANG"));
$langUsed = strtoupper($langUsed);

/* instanzio la classe translator */
$locale = new f734Translator($langUsed);

if(count($argv)<3) {
   usage($argv[0], $locale->locale);
   return(0);
}


$dev = $argv[1];
$optBauds = 9600;
$optBits = 7;
$optParity = 'E';

/* prendo le eventuali opzioni */
for($k = 2; $k < count($argv); $k++) {
   if(strncmp($argv[$k], '-o', 2)==0) {
      list($tmpNull, $options) = explode("=", $argv[$k]);
      list($optBauds, $optBits, $optParity) = explode(",", $options);
      if($optBauds == '') $optBauds = DEFAULT_BAUDS;
      if($optBits < '7' || $optBits > '9') $optBits = DEFAULT_BITS;
      if($optParity != 'N' && $optParity != 'E' && $optParity != 'O') $optParity = DEFAULT_PARITY;
   } else if(strncmp($argv[$k], '-c', 2)==0) {
      $tmpNull = substr($argv[$k], 2);
      if($tmpNull == 'READ') $mode = READ;
      else if($tmpNull == 'DELETE') $mode = DELETE;
      else die(sprintf($locale->getMessage('comando_non_supportato'), $tmpNull));
   } else if(strncmp($argv[$k], '-d', 2)==0) {
      $mode = DELETE;
   } else if(strncmp($argv[$k], '-f', 2)==0) {
      $programOptions = $programOptions | WRITE_FILE;
      $optFile = substr($argv[$k], 2);
   } else if(strncmp($argv[$k], '-v', 2)==0) {
      $programOptions = $programOptions | VERBOSE;
   } else if(strncmp($argv[$k], '-h', 2)==0) {
      usage($argv[0], $locale->locale);
      die();
   } else {
      echo sprintf($locale->getMessage('opzione_sconosciuta_help'), $argv[$k], $argv[0]);
   }

} // END for 

/* controlli correttezza informazioni */
if($mode == '') die($locale->getMessage('necessaria_opzione_c'));


/* inizio elaborazione */
if($programOptions & VERBOSE) {
   echo  "\n".ABSTRACT_PRG." - Ver. ".ABSTRACT_VER." - ".ABSTRACT_CPY."\n".
         "|--- ".$locale->getMessage('modalita').".....: ".(($mode == READ)?$locale->getMessage('download'):$locale->getMessage('cancellazione'))."\n".
         "|--- ".$locale->getMessage('periferica')."...: ".$dev."\n".
         "|--- Bauds........: ".$optBauds."\n".
         "|--- Bits.........: ".$optBits."\n".
         "|--- ".$locale->getMessage('parita').".......: ".$optParity."\n";
}

/* converto l'opzione paritÃ  */
if($optParity == 'E') $tmpParity = 2;
else if($optParity == 'O') $tmpParity = 1;
else if($optParity == 'N') $tmpParity = 0;

$arrayOpt = array(   'bauds' => (int) $optBauds,
                     'bits' => (int) $optBits,
                     'stop' => 1,
                     'parity' => (int) $tmpParity );


/* istanzio la classe F734 */
$f734 = new f734Protocol();
$f734->setLocale($locale);
$f734->checksumMode = MOD256;

$f734->fd = dio_open($dev, O_RDWR | O_NOCTTY | O_NONBLOCK);
if($f734->fd == NULL) {
   die(sprintf($locale->getMessage('impossibile_aprire_seriale'), $dev));
}

/* apertura e impostazione seriale */
dio_fcntl($f734->fd, F_SETFL, O_SYNC);
dio_fcntl($f734->fd, F_SETFL, O_NDELAY);
dio_tcsetattr($f734->fd, $arrayOpt);

/* prendo l'identificativo del terminalino */
$f734->checkAndDetectModel();
$f734->detectModelData();

if($programOptions & VERBOSE) echo "|--- ".$locale->getMessage('terminale')."....: ".$f734->devModel." - ".$f734->devFirmware." - ".$f734->devRam."\n";

/* modalitÃ  di lavoro */
if($mode == READ) {           /* modalità  DOWNLOAD */
   $f734->downloadData();
   if($programOptions & VERBOSE) echo "|--- ".$locale->getMessage('record_letti').".: ".$f734->getTotRecords()." \n";
   $f734->printData();
}  else if($mode == DELETE) { /* modalità DELETE */
   $f734->deleteData();
   if($programOptions & VERBOSE) echo "|--- ".$locale->getMessage('dati_terminalino_eliminati').". \n";
}

dio_close($f734->fd);

?>
