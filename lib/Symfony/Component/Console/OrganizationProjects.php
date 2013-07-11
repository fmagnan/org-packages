<?php

namespace Symfony\Component\Console;

class OrganizationProjects extends Command\Command
{

    protected $outputFilePath;

    public function __construct($outputFilePath, $name = null)
    {
        $this->outputFilePath = $outputFilePath;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setName('org:packages')
            ->setDescription('Retrieve packages and projects from organization')
            ->addArgument(
                'organization',
                Input\InputArgument::REQUIRED,
                'name of organization in GitHub to retrieve packages from'
            )
            ->addArgument(
                'token',
                Input\InputArgument::OPTIONAL,
                'valid application token'
            );
    }

    protected function startsWith($haystack, $needle)
    {
        return !strncmp($haystack, $needle, strlen($needle));
    }

    protected function buildDirectory(array $projects, $organization)
    {
        $directory = array();
        foreach ($projects as $main => $dependencies) {
            if (!isset($directory[$main])) {
                $info = $this->getInfoFromPackagist($main, $organization);
                $directory[$main] = $info;
            }
            foreach ($dependencies as $dependency) {
                if (!isset($directory[$dependency])) {
                    $info = $this->getInfoFromPackagist($dependency, $organization);
                    $directory[$dependency] = $info;
                }
            }
        }

        return $directory;
    }

    protected function retrieveProjects($github, $organization, $repositories, Output\OutputInterface $output)
    {
        $projects = array();
        foreach ($repositories as $repository) {
            $repositoryName = $repository['name'];

            $output->write('scanning ' . $repositoryName . '... ');
            try {
                $composerJsonContent = $github->api('repository')->contents()->download(
                    $organization,
                    $repositoryName,
                    'composer.json'
                );
                $composerJsonData = json_decode($composerJsonContent);
                if (property_exists($composerJsonData, 'require')) {
                    $dependencies = array_keys((array)$composerJsonData->require);
                    foreach ($dependencies as $dependency) {
                        if ('php' != $dependency) {
                            $projects[$organization . '/' . $repositoryName][] = $dependency;
                        }
                    }
                    $output->writeln('adding packages.');
                }
            } catch (\Github\Exception\RuntimeException $e) {
                $output->writeln('no "composer.json" file found, skipping.');
            }
        }
        return $projects;
    }

    protected function execute(Input\InputInterface $input, Output\OutputInterface $output)
    {
        $authenticationToken = $input->getArgument('token');
        $organization = $input->getArgument('organization');

        $github = new \Github\Client();
        if (!empty($authenticationToken)) {
            $github->authenticate($authenticationToken, \Github\Client::AUTH_HTTP_TOKEN);
        }

        $repositories = $github->api('organization')->repositories($organization);

        $output->writeln('retrieving ' . count($repositories) . ' projects from ' . $organization . ' organization' . PHP_EOL);

        $projects=$this->retrieveProjects($github, $organization, $repositories,  $output);

        $output->writeln('building directory');

        $data = array(
            'directory' => $this->buildDirectory($projects, $organization),
            'organization' => $organization,
            'projects' => $projects,
        );

        $fileContents = '<?php return ' . trim(var_export($data, true)) . ';';

        file_put_contents($this->outputFilePath, $fileContents);
    }

    protected function getInfoFromPackagist($packageName, $organization)
    {
        if ($this->startsWith($packageName, $organization)) {
            return array('githubUrl' => 'https://github.com/' . $packageName);
        }
        try {
            $packagist = new \Packagist\Api\Client();
            $package = $packagist->get($packageName);
            $version = current($package->getVersions());
            $source = $version->getSource();
            $packageData = array(
                'description' => $package->getDescription(),
                'homepageUrl' => $version->getHomepage(),
                'packagistUrl' => 'https://packagist.org/packages/' . $packageName,
                'githubUrl' => $source->getUrl()
            );
            return $packageData;
        } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
            return array();
        }
    }

}