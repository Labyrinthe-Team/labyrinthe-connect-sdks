'use client';
// app/verify/page.tsx — composant client : le widget n'a besoin d'aucune clé.
import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';

export default function VerifyPage() {
  return (
    <main style={{ display: 'grid', placeItems: 'center', minHeight: '100vh', padding: 16 }}>
      <LabyrintheOtp
        sendUrl="/api/otp/send"
        verifyUrl="/api/otp/verify"
        locale="fr"
        style={{ width: '100%' }}
        onError={(error) => {
          console.warn(error.code);
        }}
        onVerified={({ data }) => {
          // UX only: the session cookie was already set by onVerified() on the server.
          const redirect = (data as { redirect?: string } | null)?.redirect ?? '/';
          window.location.assign(redirect);
        }}
      />
    </main>
  );
}
