<?php
// github_repositorysite.php -- Peteramati GitHub Classroom repositories
// Peteramati is Copyright (c) 2013-2018 Eddie Kohler
// See LICENSE for open-source distribution terms

class GitHubResponse implements JsonSerializable {
    public $url;
    public $status = 509;
    public $status_text;
    public $headers = [];
    public $content;
    public $j;
    function __construct($url) {
        $this->url = $url;
    }
    function jsonSerialize() {
        return $this->j ? : ["status" => $this->status, "content" => $this->content];
    }
}

class GitHub_RepositorySite extends RepositorySite {
    public $conf;
    public $base;
    public $siteclass = "github";
    function __construct($url, $base, Conf $conf) {
        $this->url = $url;
        $this->base = $base;
        $this->conf = $conf;
    }

    const MAINURL = "https://github.com/";
    static function make_url($url, Conf $conf) {
        $url = preg_replace('_\s*/\s*_', '/', $url);
        if (preg_match('_\A(?:github(?:\.com)?[:/])?/*([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)github.com(?::/*|/+)([^/]+?/[^/]+?)(?:\.git|)\z_i', $url, $m))
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        return null;
    }
    static function sniff_url($url) {
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)github.com(?::/*|/+)(.*?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A(?:github(?:\.com)?)(?::/*|/+)([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A/*([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 1;
        return 0;
    }
    static function home_link($html) {
        return Ht::link($html, self::MAINURL);
    }

    static function api(Conf $conf, $url, $post_data = null) {
        $token = $conf->opt("githubOAuthToken");
        if (!$token || $conf->opt("disableRemote"))
            return false;
        $header = "Accept: application/vnd.github.v3+json\r\n"
            . "Authorization: token $token\r\n"
            . "User-Agent: kohler/peteramati\r\n";
        $htopt = ["timeout" => (float) $conf->validate_timeout, "ignore_errors" => true, "header" => $header];
        if ($post_data !== null) {
            $header .= "Content-Length: " . strlen($post_data) . "\r\n";
            $htopt["method"] = "POST";
            $htopt["content"] = $post_data;
        }
        $context = stream_context_create(array("http" => $htopt));
        $response = new GitHubResponse($url);
        if (($stream = fopen($url, "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = get($metadata, "wrapper_data"))
                && is_array($w)) {
                if (preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m)) {
                    $response->status = (int) $m[1];
                    $response->status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i)
                    if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m))
                        $response->headers[strtolower($m[1])] = $m[2];
            }
            $response->content = stream_get_contents($stream);
            if ($response->content !== false)
                $response->j = json_decode($response->content);
            fclose($stream);
        }
        return $response;
    }
    static function api_next(Conf $conf, GitHubResponse $response) {
        if (isset($response->headers["link"])
            && preg_match('@(?:,|\A)\s*<(.*?)>\s*;\s*rel=\"?next\"?@', $response->headers["link"], $m))
            return self::api($conf, $m[1]);
        return false;
    }
    static function api_find_first(Conf $conf, $url, $callback) {
        $resp = self::api($conf, $url);
        while ($resp && $resp->status == 200 && is_array($resp->j)) {
            foreach ($resp->j as $what)
                if (($result = call_user_func($callback, $what, $resp)) !== null
                    && $result !== false)
                    return $result;
            $resp = self::api_next($conf, $resp);
        }
        return false;
    }
    static function graphql(Conf $conf, $post_data, $preencoded = false) {
        $token = $conf->opt("githubOAuthToken");
        if (!$token || $conf->opt("disableRemote"))
            return false;
        if (is_string($post_data) && !$preencoded)
            $post_data = json_encode(["query" => $post_data]);
        $header = "Authorization: token $token\r\n"
            . "User-Agent: kohler/peteramati\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($post_data) . "\r\n";
        $htopt = ["timeout" => (float) $conf->validate_timeout,
            "ignore_errors" => true, "method" => "POST",
            "header" => $header, "content" => $post_data];
        $context = stream_context_create(array("http" => $htopt));
        $response = new GitHubResponse("https://api.github.com/graphql");
        if (($stream = fopen("https://api.github.com/graphql", "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = get($metadata, "wrapper_data"))
                && is_array($w)) {
                if (preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m)) {
                    $response->status = (int) $m[1];
                    $response->status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i)
                    if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m))
                        $response->headers[strtolower($m[1])] = $m[2];
            }
            $response->content = stream_get_contents($stream);
            if ($response->content !== false)
                $response->j = json_decode($response->content);
            fclose($stream);
        }
        return $response;
    }

    static function echo_username_form(Contact $user, $first) {
        global $Me;
        if (!$first && !$user->github_username)
            return;
        echo Ht::form(hoturl_post("index", array("set_username" => 1, "u" => $Me->user_linkpart($user), "reposite" => "github"))),
            '<div class="f-contain">';
        $notes = array();
        if (!$user->github_username)
            $notes[] = array(true, "Please enter your " . self::home_link("GitHub") . " username and click “Save.”");
        ContactView::echo_group(self::home_link("GitHub") . " username",
                                Ht::entry("username", $user->github_username)
                                . "  " . Ht::submit("Save"), $notes);
        echo "</div></form>";
    }
    static function save_username(Contact $user, $username) {
        global $Me;
        // does it contain odd characters?
        $username = trim((string) $username);
        if ($username == "") {
            if ($Me->privChair)
                return $user->change_username("github", null);
            return Conf::msg_error("Empty username.");
        }
        if (preg_match('_[@,;:~/\[\](){}\\<>&#=\\000-\\027]_', $username))
            return Conf::msg_error("The username “" . htmlspecialchars($username) . "” contains funny characters. Remove them.");

        // is it in use?
        $x = $user->conf->fetch_value("select contactId from ContactInfo where github_username=?", $username);
        if ($x && $x != $user->contactId)
            return Conf::msg_error("That username is already in use.");

        // is it valid? XXX GitHub API
        $response = null;
        if (($org = $user->conf->opt("githubOrganization"))) {
            $url = "https://api.github.com/orgs/" . urlencode($org) . "/members/" . urlencode($username);
            $response = self::api($user->conf, $url);
            if ($response->status == 404 && $user->conf->opt("githubRequireOrganizationMembership"))
                return Conf::msg_error("That user isn’t a member of the " . Ht::link(htmlspecialchars($org) . " organization", self::MAINURL . urlencode($org)) . " that manages the class. Follow the link for registering with the class, or contact course staff.");
        }
        if (!$response || $response->status == 404) {
            $url = "https://api.github.com/users/" . urlencode($username);
            $response = self::api($user->conf, $url);
        }

        if ($response->status == 404)
            return Conf::msg_error("That username doesn’t appear to exist. Check your spelling.");
        else if ($response->status != 200 && $response->status != 204)
            return Conf::msg_error("Error contacting " . htmlspecialchars($userurl) . " (response code $response_code). Maybe try again?");

        if ($org && ($staff_team = $user->conf->opt("githubStaffTeam")) && $user->is_student()) {
            if (!is_int($staff_team)) {
                $staff_team = self::api_find_first($user->conf, "https://api.github.com/orgs/" . urlencode($org) . "/teams?per_page=100", function ($team) use ($staff_team) {
                    if (isset($team->name) && $team->name === $staff_team)
                        return $team->id;
                });
            }
            if (is_int($staff_team)) {
                $tresp = self::api($user->conf, "https://api.github.com/teams/$staff_team/members/" . urlencode($username));
                if ($tresp->status == 200 || $tresp->status == 204)
                    return Conf::msg_error("That username belongs to a member of the course staff.");
            }
        }

        return $user->change_username("github", $username);
    }

    function friendly_siteclass() {
        return "GitHub";
    }
    static function global_friendly_siteclass() {
        return "GitHub";
    }
    static function global_friendly_siteurl() {
        return "https://github.com/";
    }

    function web_url() {
        return "https://github.com/" . $this->base;
    }
    function ssh_url() {
        return "git@github.com:" . $this->base;
    }
    function git_url() {
        return "git://github.com/" . $this->base;
    }
    function friendly_url() {
        return $this->base ? : $this->url;
    }
    function owner_name() {
        if (preg_match('{\A([^/"\\\\]+)/([^/"\\\\]+)\z}', $this->base, $m))
            return [$m[1], $m[2]];
        else
            return false;
    }

    function message_defs(Contact $user) {
        $base = $user->is_anonymous ? "[anonymous]" : $this->base;
        return ["REPOURL" => "https://github.com/$base", "REPOGITURL" => "git@github.com:$base", "REPOBASE" => $base, "GITHUB" => 1];
    }
    function expand_message($name, Contact $user) {
        return Messages::$main->expand_html($name, $this->message_defs($user));
    }

    function validate_open(MessageSet $ms = null) {
        $owner_name = $this->owner_name();
        if (!$owner_name)
            return -1;
        $gql = self::graphql($this->conf,
            "{ repository(owner:\"{$owner_name[0]}\", name:\"{$owner_name[1]}\") { isPrivate } }");
        if ($gql->status !== 200
            || !$gql->j
            || !isset($gql->j->data)) {
            error_log(json_encode($gql));
            return -1;
        } else if ($gql->j->data->repository == null) {
            $ms && $ms->set_error_html("open", $this->expand_message("repo_nonexistent", $ms->user));
            return 1;
        } else if (!$gql->j->data->repository->isPrivate) {
            $ms && $ms->set_error_html("open", $this->expand_message("repo_toopublic", $ms->user));
            return 1;
        } else
            return 0;
    }
    function validate_working(MessageSet $ms = null) {
        $status = RepositorySite::run_ls_remote($this->conf, $this->ssh_url(), $output);
        $answer = join("\n", $output);
        if ($status == 0 && $ms)
            $ms->set_error_html("working", $this->expand_message("repo_unreadable", $ms->user));
        if ($status > 0 && !preg_match(',^[0-9a-f]{40}\s+refs/heads/master,m', $answer)) {
            if ($ms)
                $ms->set_error_html("working", $this->expand_message("repo_nomaster", $ms->user));
            $status = 0;
        }
        return $status;
    }
    function validate_ownership_always() {
        return false;
    }
    function validate_ownership(Repository $repo, Contact $user, Contact $partner = null,
                                MessageSet $ms = null) {
        if (!$user->github_username)
            return -1;
        $response = self::api($this->conf, "https://api.github.com/repos/" . $this->base . "/collaborators/" . urlencode($user->github_username));
        if ($response && $response->status == 204)
            return 1;
        if ($response && $response->status == 404)
            return 0;
        return -1;
    }
}
