# Native file primitives; publication remains disabled pending receipt integration.
Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class F02SecureOutputNative {
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 static extern SafeFileHandle CreateFile(string path, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);
 [DllImport("ntdll.dll")]
 static extern int NtCreateFile(out SafeFileHandle file, uint access, ref ObjectAttributes attributes, out IoStatus status, IntPtr allocation, uint fileAttributes, uint share, uint disposition, uint options, IntPtr ea, uint eaLength);
 [StructLayout(LayoutKind.Sequential)] struct IoStatus { public IntPtr Status; public IntPtr Information; }
 [StructLayout(LayoutKind.Sequential)] struct UnicodeString { public ushort Length, MaximumLength; public IntPtr Buffer; }
 [StructLayout(LayoutKind.Sequential)] struct ObjectAttributes { public int Length; public IntPtr RootDirectory, ObjectName; public uint Attributes; public IntPtr SecurityDescriptor, SecurityQualityOfService; }
 [DllImport("kernel32.dll", SetLastError=true)]
 static extern bool GetFileInformationByHandle(SafeFileHandle file, out Info info);
 [DllImport("advapi32.dll")] static extern uint GetSecurityInfo(SafeFileHandle handle, int type, uint information, out IntPtr owner, out IntPtr group, out IntPtr dacl, out IntPtr sacl, out IntPtr descriptor);
 [DllImport("advapi32.dll")] static extern uint GetSecurityDescriptorLength(IntPtr descriptor);
 [DllImport("kernel32.dll")] static extern IntPtr LocalFree(IntPtr memory);
 [StructLayout(LayoutKind.Sequential)]
 struct Info { public uint Attributes, CreationLow, CreationHigh, AccessLow, AccessHigh, WriteLow, WriteHigh, Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow; }
 static SafeFileHandle Check(SafeFileHandle h) { if(h.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error()); return h; }
 public static SafeFileHandle OpenDirectory(string path) { var h=Check(CreateFile(path, 0x20080, 7, IntPtr.Zero, 3, 0x02200000, IntPtr.Zero)); Info i; if(!GetFileInformationByHandle(h,out i)) { int e=Marshal.GetLastWin32Error(); h.Dispose(); throw new Win32Exception(e); } if((i.Attributes & 0x400) != 0 || (i.Attributes & 0x10) == 0) { h.Dispose(); throw new InvalidOperationException("Directory reparse point or wrong type"); } return h; }
 static void Name(string name) { if(String.IsNullOrEmpty(name) || name.Length > 255 || name == "." || name == ".." || name.EndsWith(".") || name.EndsWith(" ") || System.Text.RegularExpressions.Regex.IsMatch(name, "[\\x00-\\x1f\\x7f<>:\"/\\\\|?*]") || System.Text.RegularExpressions.Regex.IsMatch(name, @"^(CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[1-9¹²³]|LPT[1-9¹²³])(\.|$)", System.Text.RegularExpressions.RegexOptions.IgnoreCase | System.Text.RegularExpressions.RegexOptions.CultureInvariant)) throw new ArgumentException("unsafe name"); }
 public static SafeFileHandle CreateRelative(SafeFileHandle directory, string name) {
  Name(name); AssertOwnerOnly(directory); IntPtr chars=Marshal.StringToHGlobalUni(name), up=IntPtr.Zero, security=IntPtr.Zero;
  try { UnicodeString u=new UnicodeString { Length=(ushort)(name.Length*2), MaximumLength=(ushort)(name.Length*2), Buffer=chars }; up=Marshal.AllocHGlobal(Marshal.SizeOf(typeof(UnicodeString))); Marshal.StructureToPtr(u,up,false);
   string sid=System.Security.Principal.WindowsIdentity.GetCurrent().User.Value; var sd=new System.Security.AccessControl.RawSecurityDescriptor("O:"+sid+"D:P(A;;FA;;;"+sid+")"); byte[] sdBytes=new byte[sd.BinaryLength]; sd.GetBinaryForm(sdBytes,0); security=Marshal.AllocHGlobal(sdBytes.Length); Marshal.Copy(sdBytes,0,security,sdBytes.Length);
   ObjectAttributes a=new ObjectAttributes { Length=Marshal.SizeOf(typeof(ObjectAttributes)), RootDirectory=directory.DangerousGetHandle(), ObjectName=up, Attributes=0x40, SecurityDescriptor=security }; IoStatus s; SafeFileHandle h;
   int result=NtCreateFile(out h,0xC0110080,ref a,out s,IntPtr.Zero,0x80,0,2,0x200060,IntPtr.Zero,0);
   if(result != 0) { if(h != null) h.Dispose(); throw new InvalidOperationException("NTSTATUS 0x" + result.ToString("x8")); } try { if(s.Status != IntPtr.Zero) throw new InvalidOperationException("I/O status refused"); AssertOwnerOnly(h); AssertSingleLink(h); return h; } catch { h.Dispose(); throw; }
  } finally { if(security != IntPtr.Zero) Marshal.FreeHGlobal(security); if(up != IntPtr.Zero) Marshal.FreeHGlobal(up); Marshal.FreeHGlobal(chars); }
 }
 public static void AssertTestMarker(SafeFileHandle directory) {
  AssertOwnerOnly(directory); string name="test-only.marker"; IntPtr chars=Marshal.StringToHGlobalUni(name), up=IntPtr.Zero; SafeFileHandle h=null;
  try { UnicodeString u=new UnicodeString { Length=(ushort)(name.Length*2), MaximumLength=(ushort)(name.Length*2), Buffer=chars }; up=Marshal.AllocHGlobal(Marshal.SizeOf(typeof(UnicodeString))); Marshal.StructureToPtr(u,up,false);
   ObjectAttributes a=new ObjectAttributes { Length=Marshal.SizeOf(typeof(ObjectAttributes)), RootDirectory=directory.DangerousGetHandle(), ObjectName=up, Attributes=0x40 }; IoStatus s;
   int result=NtCreateFile(out h,0x00120081,ref a,out s,IntPtr.Zero,0,0,1,0x200060,IntPtr.Zero,0);
   if(result != 0 || s.Status != IntPtr.Zero) throw new InvalidOperationException("marker refused"); AssertOwnerOnly(h); AssertSingleLink(h);
   using(var borrowed=new SafeFileHandle(h.DangerousGetHandle(),false)) using(var stream=new System.IO.FileStream(borrowed,System.IO.FileAccess.Read)) { byte[] expected=System.Text.Encoding.ASCII.GetBytes("f02-synthetic-test-only-v1"); if(stream.Length != expected.Length) throw new InvalidOperationException("marker refused"); foreach(byte b in expected) if(stream.ReadByte() != b) throw new InvalidOperationException("marker refused"); }
  } finally { if(h != null) h.Dispose(); if(up != IntPtr.Zero) Marshal.FreeHGlobal(up); Marshal.FreeHGlobal(chars); }
 }
 static SafeFileHandle OpenRelativeDirectory(SafeFileHandle directory, string name) {
  Name(name); IntPtr chars=Marshal.StringToHGlobalUni(name), up=IntPtr.Zero;
  try { UnicodeString u=new UnicodeString { Length=(ushort)(name.Length*2), MaximumLength=(ushort)(name.Length*2), Buffer=chars }; up=Marshal.AllocHGlobal(Marshal.SizeOf(typeof(UnicodeString))); Marshal.StructureToPtr(u,up,false);
   ObjectAttributes a=new ObjectAttributes { Length=Marshal.SizeOf(typeof(ObjectAttributes)), RootDirectory=directory.DangerousGetHandle(), ObjectName=up, Attributes=0x40 }; IoStatus s; SafeFileHandle h;
   int result=NtCreateFile(out h,0x00120080,ref a,out s,IntPtr.Zero,0,7,1,0x200021,IntPtr.Zero,0);
   if(result != 0) { if(h != null) h.Dispose(); throw new InvalidOperationException("NTSTATUS 0x" + result.ToString("x8")); } if(s.Status != IntPtr.Zero) { h.Dispose(); throw new InvalidOperationException("I/O status refused"); }
   Info i; if(!GetFileInformationByHandle(h,out i)) { int e=Marshal.GetLastWin32Error(); h.Dispose(); throw new Win32Exception(e); } if((i.Attributes & 0x400) != 0 || (i.Attributes & 0x10) == 0) { h.Dispose(); throw new InvalidOperationException("reparse or wrong type"); } return h;
  } finally { if(up != IntPtr.Zero) Marshal.FreeHGlobal(up); Marshal.FreeHGlobal(chars); }
 }
 public static SafeFileHandle WalkDirectory(string path) {
  if(path == null || path.Length < 3 || !Char.IsLetter(path[0]) || path[1] != ':' || path[2] != '\\') throw new ArgumentException("absolute drive path required");
  var drive=new System.IO.DriveInfo(path.Substring(0,3)); if(drive.DriveType != System.IO.DriveType.Fixed || !String.Equals(drive.DriveFormat,"NTFS",StringComparison.OrdinalIgnoreCase)) throw new InvalidOperationException("fixed NTFS volume required");
  SafeFileHandle current=OpenDirectory(path.Substring(0,3));
  try { Info root; if(!GetFileInformationByHandle(current,out root)) throw new Win32Exception(Marshal.GetLastWin32Error());
   foreach(string component in path.Substring(3).Split('\\')) { Name(component); SafeFileHandle next=OpenRelativeDirectory(current,component); Info info; if(!GetFileInformationByHandle(next,out info) || info.Volume != root.Volume) { next.Dispose(); throw new InvalidOperationException("volume or identity refused"); } current.Dispose(); current=next; }
   SafeFileHandle result=current; current=null; return result;
  } finally { if(current != null) current.Dispose(); }
 }
 public static string Identity(SafeFileHandle h) { Info i; if(!GetFileInformationByHandle(h,out i)) throw new Win32Exception(Marshal.GetLastWin32Error()); return i.Volume.ToString("x8") + ":" + i.IndexHigh.ToString("x8") + i.IndexLow.ToString("x8"); }
 public static void AssertSingleLink(SafeFileHandle h) { Info i; if(!GetFileInformationByHandle(h,out i)) throw new Win32Exception(Marshal.GetLastWin32Error()); if(i.Links != 1 || (i.Attributes & 0x410) != 0) throw new InvalidOperationException("unsafe file attributes or link count"); }
 public static void AssertOwnerOnly(SafeFileHandle h) {
  IntPtr owner,group,dacl,sacl,descriptor; uint result=GetSecurityInfo(h,1,5,out owner,out group,out dacl,out sacl,out descriptor); if(result != 0) throw new Win32Exception((int)result);
  try { int len=checked((int)GetSecurityDescriptorLength(descriptor)); byte[] bytes=new byte[len]; Marshal.Copy(descriptor,bytes,0,len); var sd=new System.Security.AccessControl.RawSecurityDescriptor(bytes,0); var sid=System.Security.Principal.WindowsIdentity.GetCurrent().User;
   if(!sid.Equals(sd.Owner) || (sd.ControlFlags & System.Security.AccessControl.ControlFlags.DiscretionaryAclProtected) == 0 || sd.DiscretionaryAcl == null || sd.DiscretionaryAcl.Count != 1) throw new InvalidOperationException("owner or DACL refused");
   var ace=sd.DiscretionaryAcl[0] as System.Security.AccessControl.CommonAce; if(ace == null || ace.IsCallback || ace.AceQualifier != System.Security.AccessControl.AceQualifier.AccessAllowed || (ace.AceFlags & (System.Security.AccessControl.AceFlags.InheritOnly | System.Security.AccessControl.AceFlags.Inherited)) != 0 || !sid.Equals(ace.SecurityIdentifier) || (ace.AccessMask & 0x1f01ff) != 0x1f01ff) throw new InvalidOperationException("owner or DACL refused");
  } finally { LocalFree(descriptor); }
 }
}
'@
