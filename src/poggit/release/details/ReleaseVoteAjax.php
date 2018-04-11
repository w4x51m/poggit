<?php

/*
 * Poggit
 *
 * Copyright (C) 2016-2018 Poggit
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

namespace poggit\release\details;

use poggit\account\Session;
use poggit\Config;
use poggit\Meta;
use poggit\module\AjaxModule;
use poggit\release\Release;
use poggit\utils\internet\Curl;
use poggit\utils\internet\Mysql;
use function count;
use function json_encode;
use function strlen;

class ReleaseVoteAjax extends AjaxModule {
    protected function impl() {
        $releaseId = $this->param("relId");
        $vote = ((int) $this->param("vote")) <=> 0;
        $message = $this->param("message");
        $session = Session::getInstance();
        if($vote < 0 && strlen($message) < 10) $this->errorBadRequest("Negative vote must contain a message");
        if(strlen($message) > 255) $this->errorBadRequest("Message too long");
        $currState = (int) Mysql::query("SELECT state FROM releases WHERE releaseId = ?", "i", $releaseId)[0]["state"];
        if($currState !== Release::STATE_CHECKED) $this->errorBadRequest("This release is not in the CHECKED state");
        $currentReleaseDataRows = Mysql::query("SELECT p.repoId, r.state FROM projects p
                INNER JOIN releases r ON r.projectId = p.projectId
                WHERE r.releaseId = ?", "i", $releaseId);
        if(!isset($currentReleaseDataRows[0])) $this->errorBadRequest("Nonexistent release");
        $currentReleaseData = $currentReleaseDataRows[0];
        if(Curl::testPermission($currentReleaseData["repoId"], $session->getAccessToken(), $session->getName(), "push")) {
            $this->errorBadRequest("You can't vote for your own plugin!");
        }
        $uid = Session::getInstance()->getUid();
        Mysql::query("DELETE FROM release_votes WHERE user = ? AND releaseId = ?", "ii", $uid, $releaseId);
        Mysql::query("INSERT INTO release_votes (user, releaseId, vote, message) VALUES (?, ?, ?, ?)", "iiis", $uid, $releaseId, $vote, $message);
        $allVotes = Mysql::query("SELECT IFNULL(SUM(release_votes.vote), 0) AS votes FROM release_votes WHERE releaseId = ?", "i", $releaseId);
        $totalVotes = (count($allVotes) > 0) ? $allVotes[0]["votes"] : 0;

        if(!Meta::isDebug()){
            $result = Curl::curlPost(Meta::getSecret("discord.reviewHook"), json_encode([
                "username" => "Admin Audit",
                "content" => $vote > 0 ?
                    "{$session->getName()} upvoted release #$releaseId https://poggit.pmmp.io/rid/$releaseId" :
                    "{$session->getName()} downvoted release #$releaseId https://poggit.pmmp.io/rid/$releaseId\n\n```\n$message\n```",
            ]));
            if(Curl::$lastCurlResponseCode >= 400) {
                Meta::getLog()->e("Error executing discord webhook: " . $result);
            }
        }

        if($voted = ($totalVotes >= Config::VOTED_THRESHOLD)) {
            // yay, finally vote-approved!
            Mysql::query("UPDATE releases SET state = ? WHERE releaseId = ?", "ii", Release::STATE_VOTED, $releaseId);

            ReleaseStateChangeAjax::notifyRelease($releaseId, Release::STATE_CHECKED, Release::STATE_VOTED);
        }

        echo json_encode(["passed" => $voted]);
    }
}
