<?php
/**
 * Janus X509 Certificate Validator
 *
 * LICENSE
 *
 * Copyright 2013 Janus SSP group
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and limitations under the License.
 *
 * @package
 * @copyright 2010-2013 Janus SSP group
 * @license   http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 */

/**
 *
 */
class Janus_OpenSsl_Url
{
    protected $_url;
    protected $_parsed;
    protected $_trustedRootCertificateAuthorityFile;

    protected $_serverCertificatePem;
    protected $_serverCertificateChainPem;

    /**
     * @var Janus_OpenSsl_Command_SClient
     */
    protected $_connection;

    public function __construct($url)
    {
        $this->_url     = $url;
        $this->_parsed  = parse_url($url);
        if (!$this->_parsed) {
            throw new Janus_OpenSsl_Url_UnparsableUrlException("Url '$url' is not a valid URL");
        }
    }

    public function setTrustedRootCertificateAuthorityFile($file)
    {
        $this->_trustedRootCertificateAuthorityFile = $file;

        return $this;
    }

    public function getHostName()
    {
        return $this->_parsed['host'];
    }

    public function getUrl()
    {
        return $this->_url;
    }

    public function isHttps()
    {
        return ($this->_parsed && isset($this->_parsed['scheme']) && strtolower($this->_parsed['scheme']) === 'https');
    }

    public function connect()
    {
        $command = new Janus_OpenSsl_Command_SClient();
        $command->setConnectTo($this->_parsed['host']);
        $command->setShowCerts(true);
        if (isset($this->_trustedRootCertificateAuthorityFile)) {
            $command->setCertificateAuthorityFile($this->_trustedRootCertificateAuthorityFile);
        }
        $command->execute();
        $this->_connection = $command;

        return ($command->getExitStatus() === 0);
    }

    public function getServerCertificate()
    {
        if (!$this->_connection) {
            $this->connect();
        }

        $x509Command = new Janus_OpenSsl_Command_X509();
        $x509Command->execute($this->_connection->getOutput());
        $pem = $x509Command->getOutput();

        return new Janus_OpenSsl_Certificate($pem);
    }

    public function getServerCertificateChain()
    {
        $blocks = explode("\n---\n", $this->_connection->getOutput());
        $certificateOutput = $blocks[1];

        $certificatesFound = Janus_OpenSsl_Certificate_Utility::getCertificatesFromText($certificateOutput);

        return Janus_OpenSsl_Certificate_Chain_Factory::createFromCertificates($certificatesFound);
    }

    public function isCertificateValidForUrlHostname()
    {
        $urlCertificate = $this->getServerCertificate();

        $urlHost = $this->getHostName();
        $validHostNames = $urlCertificate->getValidHostNames();

        foreach ($validHostNames as $hostName) {
            if ($this->_doesHostnameMatchPattern($urlHost, $hostName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match patterns from certificates like:
     * test.example.com
     * or
     * *.test.example.com
     *
     * @param  string $hostname
     * @param  string $pattern
     * @return bool
     */
    protected function _doesHostnameMatchPattern($hostname, $pattern)
    {
        if ($hostname === $pattern) {
            return true; // Exact match
        }

        if (!substr($pattern, 0, 2)==='*.') {
            return false; // Not an exact match, not a wildcard pattern, so no match...
        }

        $pattern = substr($pattern, 2);

        if ($hostname === $pattern) {
            return true; // Exact match for pattern root, eg *.example.com also matches example.com
        }

        // Remove sub-domain
        $hostname = substr($hostname, strpos($hostname, '.') + 1);
        if ($hostname === $pattern) {
            return true;
        }

        return false;
    }
}
