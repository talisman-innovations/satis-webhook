<?php
/**
 * File webhook.php
 * 
 * @author Daniel Sturm <d.sturm@zirkeldesign.de>
 */

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

if (!file_exists(__DIR__.'/config.yml')) {
    echo "Please, define your satis configuration in a config.yml file.\nYou can use the config.yml.dist as a template.";
    exit(-1);
}

$request = Request::createFromGlobals();

$defaults = [
    'bin' => 'bin/satis',
    'json' => 'satis.json',
    'webroot' => 'web/',
    'user' => null,
    'secret' => null,
    'authorized_ips' => null
];
$config = Yaml::parse(__DIR__.'/config.yml');
$config = array_merge($defaults, $config);

if (null !== $config['authorized_ips']) {
    $ip = $request->getClientIp();
    $authorized = false;
    if (is_array($config['authorized_ips'])) {
        foreach ($config['authorized_ips'] as $authorizedIp) {
            $authorized = IpUtils::checkIp($ip, $authorizedIp);
            if ($authorized) {
                break;
            }
        }
    } else {
        $authorized = IpUtils::checkIp($ip, $config['authorized_ips']);
    }
    if (! $authorized) {
        http_response_code(403);
        exit(-1);
    }
}

$errors = [];
if (!file_exists($config['bin'])) {
    $errors[] = 'The Satis bin could not be found.';
}

if (!file_exists($config['json'])) {
    $errors[] = 'The satis.json file could not be found.';
}

if (!file_exists($config['webroot'])) {
    $errors[] = 'The webroot directory could not be found.';
}

if (!empty($errors)) {
    echo 'The build cannot be run due to some errors. Please, review them and check your config.yml:'."\n";
    foreach ($errors as $error) {
        echo '- '.$error."\n";
    }
    exit(-1);
}

// Determine GIT provider
if (key_exists('HTTP_X_GITHUB_EVENT', $_SERVER)) {
    $gitProvider = 'github';
} elseif ((key_exists('HTTP_X_GITLAB_EVENT', $_SERVER))) {
    $gitProvider = 'gitlab';
} else {
    $gitProvider = 'general';
}

switch ($gitProvider) {
case 'github':
    // Read the JSON data from GitHub
    $rawPost = file_get_contents('php://input');
    $githubData = json_decode($rawPost);

    // Check the secret
    $header = $_SERVER['HTTP_X_HUB_SIGNATURE'];
    list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2) + ['', ''];
    try {
        if ($hash !== hash_hmac($algo, $rawPost, $config['secret'])) {
            throw new \Exception('Hook secret does not match.');
        }
    }
    catch (Exception $e) {
        header('HTTP/1.1 403 Forbidden');
        echo $e->getMessage();die;
    }

    // Get the repo URL's
    $cloneUrl = $githubData->repository->clone_url;
    $sshUrl = $githubData->repository->ssh_url;
    break;

case 'gitlab':
    // Read the JSON data from GitLab
    $rawPost = file_get_contents('php://input');
    $gitlabData = json_decode($rawPost);

    $receivedSecret = $_SERVER['HTTP_X_GITLAB_TOKEN'];
    try {
        if ($receivedSecret !== $config['secret']) {
            throw new \Exception('Hook secret does not match.');
        }
    }
    catch (Exception $e) {
        header('HTTP/1.1 403 Forbidden');
        echo $e->getMessage();die;
    }

    // Get the repo URL's
    $cloneUrl = $gitlabData->project->http_url;
    $sshUrl = $gitlabData->project->ssh_url;
    break;

default:
}

// Read the satis JSON
$satisData = json_decode(file_get_contents('satis.json'));

$command = null;

if (in_array($gitProvider, ['github', 'gitlab'], true)) {
    // Update a specific repository if the webhook call came from GitHub or GitLab
    foreach ($satisData->repositories as $repository) {
        if (in_array($repository->url, [$cloneUrl, $sshUrl], true)) {
            $repositoryUrl = $repository->url;
        }
    }

    $command = [
        $config['bin'],
        'build',
        '--repository-url',
        $repositoryUrl,
        $config['json'],
        $config['webroot'],
    ];
} else {
    // Update all repositories if the webhook didn't come from GitHub or GitLab
    $command = [
        $config['bin'],
        'build',
        $repositoryUrl,
        $config['json'],
        $config['webroot'],
    ];
}

if (null !== $config['user']
    && !is_null($command)
) {
    $command = sprintf('sudo -u %s -i %s', $config['user'], $command);
}

$process = new Process($command);
$exitCode = $process->run(
    function ($type, $buffer) {
        if (Process::ERR === $type) {
            echo 'E';
            error_log($buffer);
        } else {
            echo '.';
        }
    }
);

echo "\n\n" . ($exitCode === 0 ? 'Successful rebuild!' : 'Oops! An error occured!') . "\n";
