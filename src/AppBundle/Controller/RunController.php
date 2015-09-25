<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

use AppBundle\Command\CustomApplication;

/**
 * HTTP interface between Slack requests and our app.
 */
class RunController extends Controller
{
    /**
     * HTTP Slack command endpoint.
     * Converts request to Symfony Console command call.
     *
     * @param Request $request The request
     *
     * @return Response The command response (200) or an error (400).
     */
    public function runAction(Request $request)
    {
        $content = (object) $request->request->all();

        $text = trim($content->text);

        #=> Determine the command instance
        $command = explode(' ', $text)[0];

        switch($command) {
            case 'help':
                # whitelist help and rewrite command
                $text = str_replace('help ', 'help gk:', $text);
                break;
            case '':
            case 'list':
                # whitelist list and support no command
                $command = 'list';
                break;
            default:
                # otherwise prefix with "gk:"
                $command = 'gk:'.$command;
                break;
        }

        #=> Building I/O
        $input  = new ArgvInput($this->toArgv("dummy_command {$text}"));
        $output = new BufferedOutput;

        try {
            $command = $this->getCommand($command);

            #=> Run the command
            $start = microtime(true);
            $command->run($input, $output);
            $time = microtime(true) - $start;

            #=> It's all good
            $return = $output->fetch();
            $status = 200;
        }

        catch(\Exception $ex) {
            #=> Forward all errors to the client
            $time = 0;
            $return = $ex->getMessage();
            $status = 400;
        }

        finally {
            if(!$input->hasParameterOption(['--no-markdown'])) {
                $return = $this->markdownize($text, $return, $time);
            }

            return new Response($return, $status);
        }
    }

    /**
     * Surrounds command output by markdown for better formatting.
     *
     * @param string $command The command that has been issued
     * @param string $output The output to display as code
     * @param float $time Time taken in seconds
     *
     * @return string
     */
    private function markdownize($command, $output, $time = null)
    {
        $time = $time ? sprintf('took %fs', $time) : '';

        return <<<EOM
`{$command}` `{$time}`
```
{$output}
```
EOM;
    }

    /**
     * Gets a command by name in AppBundle.
     *
     * @param string $name The command name
     *
     * @return \Symfony\Component\Console\Command\Command
     *
     * @throws \InvalidArgumentException When command name given does not exist
     */
    private function getCommand($name)
    {
        $app = new CustomApplication('GeekDpt Commander', 'alpha');

        $this->get('kernel')->getBundle('AppBundle')->registerCommands($app);

        return $app->get($name);
    }

    /**
     * Converts a shell command to ARGV format.
     *
     * @param string $string Shell command
     *
     * @return array
     */
    private function toArgv($string)
    {
        $out = '';

        $break = true;
        foreach(str_split($string) as $i => $char) {
            if($char == '"' && $string[$i-1] != '\\') {
                $break = !$break;
                continue;
            } elseif($char == ' ' && $break) {
                $char = "\0";
            }

            $out .= $char;
        }

        $out = str_replace('\"', '"', $out);
        return explode("\0", $out);
    }
}
