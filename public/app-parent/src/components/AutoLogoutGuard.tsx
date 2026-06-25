import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listSettings } from '../api/settings';
import { useIdleTimeout } from '../hooks/useIdleTimeout';
import { IdleWarningDialog } from './IdleWarningDialog';

const WARNING_SECONDS = 30;
const DEFAULT_MINUTES = 15;

export function AutoLogoutGuard() {
  const { data } = useQuery({ queryKey: ['settings'], queryFn: listSettings });
  const enabled = data?.['security.auto_logout'] === true;
  const minutes = Number(data?.['security.auto_logout_minutes']) || DEFAULT_MINUTES;

  const [warning, setWarning] = useState(false);

  const doLogout = () => {
    const url = window.guardkidsApi?.logoutUrl ?? '/wp-login.php?action=logout';
    window.location.assign(url);
  };

  const { reset } = useIdleTimeout({
    enabled,
    minutes,
    warningSeconds: WARNING_SECONDS,
    onWarn: () => setWarning(true),
    onTimeout: doLogout,
    onActivityWhileWarned: () => setWarning(false),
  });

  if (!warning) return null;

  return (
    <IdleWarningDialog
      secondsLeft={WARNING_SECONDS}
      onStay={() => {
        setWarning(false);
        reset();
      }}
      onLogout={doLogout}
    />
  );
}
