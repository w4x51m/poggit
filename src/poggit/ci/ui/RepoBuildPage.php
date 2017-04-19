<?php
/*
 * Poggit
 *
 * Copyright (C) 2016-2017 Poggit
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace poggit\ci\ui;

use poggit\account\SessionUtils;
use poggit\ci\builder\ProjectBuilder;
use poggit\Mbd;
use poggit\module\VarPage;
use poggit\Poggit;
use poggit\utils\internet\CurlUtils;
use poggit\utils\internet\GitHubAPIException;
use poggit\utils\internet\MysqlUtils;
use poggit\webhook\NewGitHubRepoWebhookModule;

class RepoBuildPage extends VarPage {

    /** @var string */
    private $user;

    /** @var string */
    private $repoName;

    /** @var \stdClass */
    private $repo;

    /** @var array */
    private $projects;

    /** @var array[] */
    private $builds = [];

    /** @var bool */
    private $private;

    public function __construct(string $user, string $repo) {
        $this->user = $user;
        $this->repoName = $repo;
        $session = SessionUtils::getInstance();
        $token = $session->getAccessToken();
        $repoNameHtml = htmlspecialchars("$user/$repo");
        try {
            $this->repo = $repo = CurlUtils::ghApiGet("repos/$user/$repo", $token);
        } catch(GitHubAPIException $e) {
            $name = htmlspecialchars($session->getLogin()["name"]);
            throw new RecentBuildPage(<<<EOD
<p>The repo $repoNameHtml does not exist or is not accessible to your GitHub account (<a href="https://github.com/$name">@$name</a>).</p>
EOD
            );
        }
        $repoRow = MysqlUtils::query("SELECT private, build FROM repos WHERE repoId = $repo->id");
        if(count($repoRow) === 0 or !((int) $repoRow[0]["build"])) {
            throw new RecentBuildPage(<<<EOD
<p>The repo $repoNameHtml does not have Poggit CI enabled.</p>
EOD
            );
        }
        $this->private = (bool) (int) $repoRow[0]["private"];
        $this->projects = MysqlUtils::query("SELECT projectId, name, path, type, framework, lang FROM projects WHERE repoId = $repo->id");
        if(count($this->projects) === 0) {
            throw new RecentBuildPage(<<<EOD
<p>The repo $repoNameHtml does not have any projects yet.</p>
EOD
            );
        }
        foreach(MysqlUtils::query("
            SELECT b.buildId, b.class, b.internal, b.projectId, b.resourceId, unix_timestamp(b.created) AS creation
                FROM builds b INNER JOIN projects p ON b.projectId = p.projectId
                WHERE p.repoId = ? AND b.class IS NOT NULL
            ORDER BY b.buildId DESC", "i", $repo->id) as $build) {
            if(!isset($this->builds[$build["projectId"]]) || count($this->builds[$build["projectId"]]) < 3) $this->builds[$build["projectId"]][] = $build;
        }
    }

    public function getTitle(): string {
        return "Projects in {$this->repo->owner->login}/{$this->repo->name}";
    }

    public function output() {
        ?>
        <div class="projectswrapper">
        <div class="projectsheader">
            <h3>Projects in
                <?php Mbd::displayRepo($this->repo->owner->login, $this->repo->name, $this->repo->owner->avatar_url) ?>
                <?php if($this->private) { ?>
                    <img title="This is a private repo" width="16"
                         src="https://maxcdn.icons8.com/Android_L/PNG/24/Very_Basic/lock-24.png"/>
                <?php } ?>
            </h3>
        </div>
        <?php
        foreach($this->projects as $project) {
            $pname = $project["name"];
            $truncatedName = htmlspecialchars(substr($pname, 0, 14) . (strlen($pname) > 14 ? "..." : ""));

            ?>
            <div class="brief-info">
            <h5>
                <?= ProjectBuilder::$PROJECT_TYPE_HUMAN[$project["type"]] ?> project:
                <a href="<?= Poggit::getRootPath() ?>ci/<?= $this->repo->full_name ?>/<?= $pname === $this->repo->name ? "~" : urlencode($pname) ?>">
                    <?= htmlspecialchars($truncatedName) ?>
                </a>
                <?php Mbd::ghLink($this->repo->html_url . "/" . "tree/" . $this->repo->default_branch . "/" . $project["path"]) ?>
            </h5>
            <h5>Latest Builds</h5>
            <?php if(!isset($this->builds[$project["projectId"]])) { ?>
                <p style="font-style: italic;">This project has no builds yet.</p>
                <p class="remark">Contact the owner of this repo for details.</p>
                </div>
            <?php } else { ?>
                <ul>
                    <?php
                    foreach($this->builds[$project["projectId"]] as $build) {
                        $resId = (int) $build["resourceId"];
                        ?>
                        <li><?= ProjectBuilder::$BUILD_CLASS_HUMAN[$build["class"]] ?> build
                            <?php
                            Mbd::showBuildNumbers($build["buildId"], $build["internal"], "ci/{$this->repo->full_name}/" . urlencode($pname) . "/" .
                                ProjectBuilder::$BUILD_CLASS_IDEN[$build["class"]] . ":" . $build["internal"])
                            ?>:
                            <a href="<?= Poggit::getRootPath() ?>r/<?= $resId ?>/<?= $pname ?>.phar"
                               class="action">
                                Direct download</a>
                            (<a onclick='promptDownloadResource(<?= $resId ?>,
                            <?= json_encode($pname . ".phar", JSON_UNESCAPED_SLASHES) ?>);' href="#"
                            >Download with custom filename</a>)
                            <?php if($this->private) { ?>
                                <br/>
                                <img title="This is a private repo" width="16"
                                     src="https://maxcdn.icons8.com/Android_L/PNG/24/Very_Basic/lock-24.png"/>
                                This is a private repo. You must provide a GitHub access token if you download this
                                plugin without browser (e.g. through <code>curl</code> or <code>wget</code>). See
                                <a href="<?= Poggit::getRootPath() ?>help.resource.private">this article</a> for
                                details.
                            <?php } ?>
                            <br/>
                            Created: <span class="time" data-timestamp="<?= $build["creation"] ?>"></span>
                        </li>
                    <?php } ?>
                </ul>
                </div>
            <?php } ?>
        <?php } ?>
        <?php if(isset($this->repo->permissions) and $this->repo->permissions->admin) { ?>
            <div class="projecthelp">
            <p class="remark">Some projects / No builds are showing up? Follow these quick steps for fixing:</p>
            <ol class="remark">
                <li>Go to the webhooks page in your repo settings
                    <?php Mbd::ghLink($this->repo->html_url . "/settings/hooks") ?></li>
                <li>Look for the Poggit webhook (it should start with
                    <code class="code"><?= NewGitHubRepoWebhookModule::extPath() ?></code>) and click on it
                </li>
                <li>Scroll to the bottom "Recent Deliveries" and expand the first delivery from the top</li>
                <li>Does the delivery say "Service timeout"? If yes, you may be having too many projects in a single
                    repo.
                </li>
                <li>Otherwise, switch to the "Response" tab. Read the response message generated by Poggit. If a
                    server-side error message is seen, it is a Poggit issue. Please report your issue on Poggit
                    <?php Mbd::ghLink("https://github.com/poggit/poggit/issues") ?>, but please make sure you are
                    not
                    providing confidential information when reporting on a public issue tracker.
                </li>
                <li>Or, if the response points to a problem in your Poggit manifest, please read the error and fix the
                    problem yourself, for example, editing the .poggit.yml file you are using if it is invalid, etc.
                </li>
            </ol>
        <?php } ?>
        </div>
        <?php
    }

    public function getMetaDescription(): string {
        return "Projects in {$this->repo->full_name} built by Poggit";
    }

}

// TODO allow deleting projects
