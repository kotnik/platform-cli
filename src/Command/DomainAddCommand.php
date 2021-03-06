<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends PlatformCommand
{

    // The final array of SSL options for the client parameters.
    protected $sslOptions = array();

    protected $domainName;

    protected $certPath;
    protected $keyPath;
    protected $chainPaths;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('domain:add')
          ->setDescription('Add a new domain to the project')
          ->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'The name of the domain'
          )
          ->addOption(
            'project',
            null,
            InputOption::VALUE_OPTIONAL,
            'The project ID'
          )
          // @todo: Implement interactive SSL file entry
          // ->addOption('ssl', null, InputOption::VALUE_NONE, 'Specify an SSL certificate chain in interactive mode.')
          ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file for this domain.')
          ->addOption(
            'key',
            null,
            InputOption::VALUE_REQUIRED,
            'The path to the private key file for the provided certificate.'
          )
          ->addOption(
            'chain',
            null,
            InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
            'The path to the certificate chain file or files for the provided certificate.'
          );
    }

    protected function validateInput(InputInterface $input, OutputInterface $output, $envNotRequired = null)
    {
        parent::validateInput($input, $output);

        $this->domainName = $input->getArgument('name');
        if (empty($this->domainName)) {
            $output->writeln("<error>You must specify the name of the domain.</error>");

            return false;
        } else {
            if (!$this->validDomain($this->domainName)) {
                $output->writeln("<error>You must specify a valid domain name.</error>");

                return false;
            }
        }

        $this->certPath = $input->getOption('cert');
        $this->keyPath = $input->getOption('key');
        $this->chainPaths = $input->getOption('chain');
        if ($this->certPath || $this->keyPath || $this->chainPaths) {
            return $this->validateSslOptions();
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $wildcard = $this->getHelper('question')
                         ->confirm("Is your domain a wildcard?", $input, $output, false);

        // @todo: Ask about SSL uploads if option --ssl is specified instead of inline filenames

        // Beam the package up to the mothership.
        $this->getSelectedProject()
             ->addDomain($this->domainName, $wildcard, $this->sslOptions);

        // @todo: Add proper exception/error handling here...seriously.
        $output->writeln("The domain <info>{$this->domainName}</info> was successfully added to the project");

        return 0;
    }

    protected function validateSslOptions()
    {
        // Get the contents.
        if (!is_readable($this->certPath)) {
            throw new \Exception(
              "The certificate file could not be read: " . $this->certPath
            );
        }
        $sslCert = trim(file_get_contents($this->certPath));
        // Do a bit of validation.
        $certResource = openssl_x509_read($sslCert);
        if (!$certResource) {
            throw new \Exception(
              "The certificate file is not a valid X509 certificate."
            );
        }
        // Then the key. Does it match?
        if (!is_readable($this->keyPath)) {
            throw new \Exception(
              "The private key file could not be read: " . $this->keyPath
            );
        }
        $sslPrivateKey = trim(file_get_contents($this->keyPath));
        $keyResource = openssl_pkey_get_private($sslPrivateKey);
        if (!$keyResource) {
            throw new \Exception(
              "The provided private key is not valid, or it is passphrase-protected."
            );
        }
        $keyMatch = openssl_x509_check_private_key($certResource, $keyResource);
        if (!$keyMatch) {
            throw new \Exception("The provided certificate does not match the provided private key.");
        }
        // Each chain needs to be a valid cert.
        $sslChainCerts = $this->readChainFiles($this->chainPaths);
        foreach ($sslChainCerts as $chainCertData) {
            $chainResource = openssl_x509_read($chainCertData);
            if (!$chainResource) {
                throw new \Exception("One of the provided certificates in the chain is not a valid X509 certificate.");
            } else {
                openssl_x509_free($chainResource);
            }
        }
        // Yay we win.
        $this->sslOptions = array(
          'certificate' => $sslCert,
          'key' => $sslPrivateKey,
          'chain' => $sslChainCerts,
        );

        return true;
    }

    /**
     * Validate a domain.
     *
     * @param string $domain
     *
     * @return bool
     */
    protected function validDomain($domain)
    {
        // @todo: Use symfony/Validator here once it gets the ability to validate just domain.
        return (bool) preg_match('/^([^\.]{1,63}\.)+[^\.]{2,63}$/', $domain);
    }

    protected function readChainFiles($chainPaths)
    {
        if (!is_array($chainPaths)) {
            // Skip out if we somehow ended up with crap here.
            return array();
        }
        $chainFiles = array();
        // @todo: We want to split up each cert in the chain, if a file has multiple individual certs.
        // Unfortunately doing this is inconvenient so we'll skip it for now.
        foreach ($chainPaths as $chainPath) {
            if (!is_readable($chainPath)) {
                throw new \Exception("The chain file could not be read: $chainPath");
            }
            $chainFiles[] = trim(file_get_contents($chainPath));
        }

        // Yay we're done.
        return $chainFiles;
    }

}
