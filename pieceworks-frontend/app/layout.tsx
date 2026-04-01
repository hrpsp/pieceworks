import type { Metadata } from "next";
import { League_Spartan } from "next/font/google";
import { Providers } from "./providers";
import "./globals.css";

const leagueSpartan = League_Spartan({
  subsets: ["latin"],
  variable: "--font-league-spartan",
  display: "swap",
});

export const metadata: Metadata = {
  title: "PieceWorks",
  description: "Piece Rate Production & Payroll — Pakistani Shoe Manufacturing",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className={leagueSpartan.variable}>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
