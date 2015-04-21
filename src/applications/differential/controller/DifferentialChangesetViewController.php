<?php

final class DifferentialChangesetViewController extends DifferentialController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();

    $rendering_reference = $request->getStr('ref');
    $parts = explode('/', $rendering_reference);
    if (count($parts) == 2) {
      list($id, $vs) = $parts;
    } else {
      $id = $parts[0];
      $vs = 0;
    }

    $id = (int)$id;
    $vs = (int)$vs;

    $load_ids = array($id);
    if ($vs && ($vs != -1)) {
      $load_ids[] = $vs;
    }

    $changesets = id(new DifferentialChangesetQuery())
      ->setViewer($viewer)
      ->withIDs($load_ids)
      ->needHunks(true)
      ->execute();
    $changesets = mpull($changesets, null, 'getID');

    $changeset = idx($changesets, $id);
    if (!$changeset) {
      return new Aphront404Response();
    }

    $vs_changeset = null;
    if ($vs && ($vs != -1)) {
      $vs_changeset = idx($changesets, $vs);
      if (!$vs_changeset) {
        return new Aphront404Response();
      }
    }

    $view = $request->getStr('view');
    if ($view) {
      $phid = idx($changeset->getMetadata(), "$view:binary-phid");
      if ($phid) {
        return id(new AphrontRedirectResponse())->setURI("/file/info/$phid/");
      }
      switch ($view) {
        case 'new':
          return $this->buildRawFileResponse($changeset, $is_new = true);
        case 'old':
          if ($vs_changeset) {
            return $this->buildRawFileResponse($vs_changeset, $is_new = true);
          }
          return $this->buildRawFileResponse($changeset, $is_new = false);
        default:
          return new Aphront400Response();
      }
    }

    if (!$vs) {
      $right = $changeset;
      $left  = null;

      $right_source = $right->getID();
      $right_new = true;
      $left_source = $right->getID();
      $left_new = false;

      $render_cache_key = $right->getID();
    } else if ($vs == -1) {
      $right = null;
      $left = $changeset;

      $right_source = $left->getID();
      $right_new = false;
      $left_source = $left->getID();
      $left_new = true;

      $render_cache_key = null;
    } else {
      $right = $changeset;
      $left = $vs_changeset;

      $right_source = $right->getID();
      $right_new = true;
      $left_source = $left->getID();
      $left_new = true;

      $render_cache_key = null;
    }

    if ($left) {
      $left_data = $left->makeNewFile();
      if ($right) {
        $right_data = $right->makeNewFile();
      } else {
        $right_data = $left->makeOldFile();
      }

      $engine = new PhabricatorDifferenceEngine();
      $synthetic = $engine->generateChangesetFromFileContent(
        $left_data,
        $right_data);

      $choice = clone nonempty($left, $right);
      $choice->attachHunks($synthetic->getHunks());

      $changeset = $choice;
    }

    $coverage = null;
    if ($right && $right->getDiffID()) {
      $unit = id(new DifferentialDiffProperty())->loadOneWhere(
        'diffID = %d AND name = %s',
        $right->getDiffID(),
        'arc:unit');

      if ($unit) {
        $coverage = array();
        foreach ($unit->getData() as $result) {
          $result_coverage = idx($result, 'coverage');
          if (!$result_coverage) {
            continue;
          }
          $file_coverage = idx($result_coverage, $right->getFileName());
          if (!$file_coverage) {
            continue;
          }
          $coverage[] = $file_coverage;
        }

        $coverage = ArcanistUnitTestResult::mergeCoverage($coverage);
      }
    }

    $spec = $request->getStr('range');
    list($range_s, $range_e, $mask) =
      DifferentialChangesetParser::parseRangeSpecification($spec);

    $parser = id(new DifferentialChangesetParser())
      ->setCoverage($coverage)
      ->setChangeset($changeset)
      ->setRenderingReference($rendering_reference)
      ->setRenderCacheKey($render_cache_key)
      ->setRightSideCommentMapping($right_source, $right_new)
      ->setLeftSideCommentMapping($left_source, $left_new);

    $parser->readParametersFromRequest($request);

    if ($left && $right) {
      $parser->setOriginals($left, $right);
    }

    $diff = $changeset->getDiff();
    $revision_id = $diff->getRevisionID();

    $can_mark = false;
    $object_owner_phid = null;
    if ($revision_id) {
      $revision = id(new DifferentialRevisionQuery())
        ->setViewer($viewer)
        ->withIDs(array($revision_id))
        ->executeOne();
      if ($revision) {
        $can_mark = ($revision->getAuthorPHID() == $viewer->getPHID());
        $object_owner_phid = $revision->getAuthorPHID();
      }
    }

    // Load both left-side and right-side inline comments.
    if ($revision) {
      $inlines = $this->loadInlineComments(
        $revision,
        array(nonempty($left, $right)),
        $left_new,
        array($right),
        $right_new);
    } else {
      $inlines = array();
    }

    if ($left_new) {
      $inlines = array_merge(
        $inlines,
        $this->buildLintInlineComments($left));
    }

    if ($right_new) {
      $inlines = array_merge(
        $inlines,
        $this->buildLintInlineComments($right));
    }

    $phids = array();
    foreach ($inlines as $inline) {
      $parser->parseInlineComment($inline);
      if ($inline->getAuthorPHID()) {
        $phids[$inline->getAuthorPHID()] = true;
      }
    }
    $phids = array_keys($phids);

    $handles = $this->loadViewerHandles($phids);
    $parser->setHandles($handles);

    $engine = new PhabricatorMarkupEngine();
    $engine->setViewer($viewer);

    foreach ($inlines as $inline) {
      $engine->addObject(
        $inline,
        PhabricatorInlineCommentInterface::MARKUP_FIELD_BODY);
    }

    $engine->process();

    $parser
      ->setUser($viewer)
      ->setMarkupEngine($engine)
      ->setShowEditAndReplyLinks(true)
      ->setCanMarkDone($can_mark)
      ->setObjectOwnerPHID($object_owner_phid)
      ->setRange($range_s, $range_e)
      ->setMask($mask);

    if ($request->isAjax()) {
      $mcov = $parser->renderModifiedCoverage();

      $coverage = array(
        'differential-mcoverage-'.md5($changeset->getFilename()) => $mcov,
      );

      return id(new PhabricatorChangesetResponse())
        ->setRenderedChangeset($parser->renderChangeset())
        ->setCoverage($coverage)
        ->setUndoTemplates($parser->getRenderer()->renderUndoTemplates());
    }

    $detail = id(new DifferentialChangesetListView())
      ->setUser($this->getViewer())
      ->setChangesets(array($changeset))
      ->setVisibleChangesets(array($changeset))
      ->setRenderingReferences(array($rendering_reference))
      ->setRenderURI('/differential/changeset/')
      ->setDiff($diff)
      ->setTitle(pht('Standalone View'))
      ->setParser($parser);

    if ($revision_id) {
      $detail->setInlineCommentControllerURI(
        '/differential/comment/inline/edit/'.$revision_id.'/');
    }

    $crumbs = $this->buildApplicationCrumbs();

    if ($revision_id) {
      $crumbs->addTextCrumb('D'.$revision_id, '/D'.$revision_id);
    }

    $diff_id = $diff->getID();
    if ($diff_id) {
      $crumbs->addTextCrumb(
        pht('Diff %d', $diff_id),
        $this->getApplicationURI('diff/'.$diff_id));
    }

    $crumbs->addTextCrumb($changeset->getDisplayFilename());

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $detail,
      ),
      array(
        'title' => pht('Changeset View'),
        'device' => false,
      ));
  }

  private function loadInlineComments(
    DifferentialRevision $revision,
    array $left,
    $left_new,
    array $right,
    $right_new) {

    assert_instances_of($left, 'DifferentialChangeset');
    assert_instances_of($right, 'DifferentialChangeset');

    $viewer = $this->getViewer();
    $all = array_merge($left, $right);

    $inlines = id(new DifferentialInlineCommentQuery())
      ->setViewer($viewer)
      ->withRevisionPHIDs(array($revision->getPHID()))
      ->execute();

    $changeset_ids = mpull($inlines, 'getChangesetID');
    $changeset_ids = array_unique($changeset_ids);
    if ($changeset_ids) {
      $changesets = id(new DifferentialChangesetQuery())
        ->setViewer($viewer)
        ->withIDs($changeset_ids)
        ->execute();
      $changesets = mpull($changesets, null, 'getID');
    } else {
      $changesets = array();
    }
    $changesets += mpull($all, null, 'getID');

    $id_map = array();
    foreach ($all as $changeset) {
      $id_map[$changeset->getID()] = $changeset->getID();
    }

    // Generate filename maps for older and newer comments. If we're bringing
    // an older comment forward in a diff-of-diffs, we want to put it on the
    // left side of the screen, not the right side. Both sides are "new" files
    // with the same name, so they're both appropriate targets, but the left
    // is a better target conceptually for users because it's more consistent
    // with the rest of the UI, which shows old information on the left and
    // new information on the right.
    $move_here = DifferentialChangeType::TYPE_MOVE_HERE;

    $name_map_old = array();
    $name_map_new = array();
    $move_map = array();
    foreach ($all as $changeset) {
      $changeset_id = $changeset->getID();

      $filenames = array();
      $filenames[] = $changeset->getFilename();

      // If this is the target of a move, also map comments on the old filename
      // to this changeset.
      if ($changeset->getChangeType() == $move_here) {
        $old_file = $changeset->getOldFile();
        $filenames[] = $old_file;
        $move_map[$changeset_id][$old_file] = true;
      }

      foreach ($filenames as $filename) {
        // We update the old map only if we don't already have an entry (oldest
        // changeset persists).
        if (empty($name_map_old[$filename])) {
          $name_map_old[$filename] = $changeset_id;
        }

        // We always update the new map (newest changeset overwrites).
        $name_map_new[$changeset->getFilename()] = $changeset_id;
      }
    }

    // Find the smallest "new" changeset ID. We'll consider everything
    // larger than this to be "newer", and everything smaller to be "older".
    $first_new_id = min(mpull($right, 'getID'));

    $results = array();
    foreach ($inlines as $inline) {
      $changeset_id = $inline->getChangesetID();
      if (isset($id_map[$changeset_id])) {
        // This inline is legitimately on one of the current changesets, so
        // we can include it in the result set unmodified.
        $results[] = $inline;
        continue;
      }

      $changeset = idx($changesets, $changeset_id);
      if (!$changeset) {
        // Just discard this inline, as it has bogus data.
        continue;
      }

      $target_id = null;

      if ($changeset_id >= $first_new_id) {
        $name_map = $name_map_new;
        $is_new = true;
      } else {
        $name_map = $name_map_old;
        $is_new = false;
      }

      $filename = $changeset->getFilename();
      if (isset($name_map[$filename])) {
        // This changeset is on a file with the same name as the current
        // changeset, so we're going to port it forward or backward.
        $target_id = $name_map[$filename];

        $is_move = isset($move_map[$target_id][$filename]);
        if ($is_new) {
          if ($is_move) {
            $reason = pht(
              'This comment was made on a file with the same name as the '.
              'file this file was moved from, but in a newer diff.');
          } else {
            $reason = pht(
              'This comment was made on a file with the same name, but '.
              'in a newer diff.');
          }
        } else {
          if ($is_move) {
            $reason = pht(
              'This comment was made on a file with the same name as the '.
              'file this file was moved from, but in an older diff.');
          } else {
            $reason = pht(
              'This comment was made on a file with the same name, but '.
              'in an older diff.');
          }
        }
      }


      // If we didn't find a target and this change is the target of a move,
      // look for a match against the old filename.
      if (!$target_id) {
        if ($changeset->getChangeType() == $move_here) {
          $filename = $changeset->getOldFile();
          if (isset($name_map[$filename])) {
            $target_id = $name_map[$filename];
            if ($is_new) {
              $reason = pht(
                'This comment was made on a file which this file was moved '.
                'to, but in a newer diff.');
            } else {
              $reason = pht(
                'This comment was made on a file which this file was moved '.
                'to, but in an older diff.');
            }
          }
        }
      }


      // If we found a changeset to port this comment to, bring it forward
      // or backward and mark it.
      if ($target_id) {
        $inline
          ->makeEphemeral(true)
          ->setChangesetID($target_id)
          ->setIsGhost(
            array(
              'new' => $is_new,
              'reason' => $reason,
            ));

        $results[] = $inline;
      }
    }

    // Filter out the inlines we ported forward which won't be visible because
    // they appear on the wrong side of a file.
    $keep_map = array();
    foreach ($left as $changeset) {
      $keep_map[$changeset->getID()][(int)$left_new] = true;
    }
    foreach ($right as $changeset) {
      $keep_map[$changeset->getID()][(int)$right_new] = true;
    }
    foreach ($results as $key => $inline) {
      $is_new = (int)$inline->getIsNewFile();
      $changeset_id = $inline->getChangesetID();
      if (!isset($keep_map[$changeset_id][$is_new])) {
        unset($results[$key]);
        continue;
      }
    }

    return $results;
  }

  private function buildRawFileResponse(
    DifferentialChangeset $changeset,
    $is_new) {

    $viewer = $this->getViewer();

    if ($is_new) {
      $key = 'raw:new:phid';
    } else {
      $key = 'raw:old:phid';
    }

    $metadata = $changeset->getMetadata();

    $file = null;
    $phid = idx($metadata, $key);
    if ($phid) {
      $file = id(new PhabricatorFileQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($phid))
        ->execute();
      if ($file) {
        $file = head($file);
      }
    }

    if (!$file) {
      // This is just building a cache of the changeset content in the file
      // tool, and is safe to run on a read pathway.
      $unguard = AphrontWriteGuard::beginScopedUnguardedWrites();

      if ($is_new) {
        $data = $changeset->makeNewFile();
      } else {
        $data = $changeset->makeOldFile();
      }

      $file = PhabricatorFile::newFromFileData(
        $data,
        array(
          'name'      => $changeset->getFilename(),
          'mime-type' => 'text/plain',
        ));

      $metadata[$key] = $file->getPHID();
      $changeset->setMetadata($metadata);
      $changeset->save();

      unset($unguard);
    }

    return $file->getRedirectResponse();
  }

  private function buildLintInlineComments($changeset) {
    $lint = id(new DifferentialDiffProperty())->loadOneWhere(
      'diffID = %d AND name = %s',
      $changeset->getDiffID(),
      'arc:lint');
    if (!$lint) {
      return array();
    }
    $lint = $lint->getData();

    $inlines = array();
    foreach ($lint as $msg) {
      if ($msg['path'] != $changeset->getFilename()) {
        continue;
      }
      $inline = new DifferentialInlineComment();
      $inline->setChangesetID($changeset->getID());
      $inline->setIsNewFile(1);
      $inline->setSyntheticAuthor('Lint: '.$msg['name']);
      $inline->setLineNumber($msg['line']);
      $inline->setLineLength(0);

      $inline->setContent('%%%'.$msg['description'].'%%%');

      $inlines[] = $inline;
    }

    return $inlines;
  }

}
