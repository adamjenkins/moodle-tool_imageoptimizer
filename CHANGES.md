# Changes

## v1.1.4

- Privacy: processed-file records now store the file owner, so data export and deletion
  requests still reach them after the image itself has been deleted. Existing records get
  their owner filled in when the plugin is upgraded.
- Privacy: the data export now includes the file path hash declared in the privacy metadata.
- Records for deleted files are now removed by the scheduled task, and are no longer kept
  indefinitely.
- Optimized images now keep their owner, author, licence, source and creation time.
- composer.json now accepts any Moodle 5.x release from 5.0 on (`^5.0`), so later 5.x
  releases are no longer excluded.
- Automated testing now covers the released Moodle 5.3 (MOODLE_503_STABLE) instead of
  Moodle's development branch.
- Tagged releases are published to the camp plugin registry.
